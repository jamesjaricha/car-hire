# Architecture

Why the system is built this way. If you only read one section, read the first.

---

## 1. How double-booking is prevented

This is the design decision everything else bends around.

### The problem

Checking whether a vehicle is free and then claiming it is a read-modify-write.
Without a lock, that is a race. Two customers reaching checkout on the last
Hilux within the same few milliseconds will both be told it is free, and both
will take it. Application-level checks lose this race every time; they just lose
it rarely enough that it survives testing and surfaces in production.

### What we do

`VehicleHoldService::place()` is the only code permitted to write to
`vehicle_holds`. It does four things in a strict order, inside one transaction:

1. **Take a row lock on the vehicle** with `lockForUpdate()`. Nothing about
   availability is read before this line, because anything read before it is
   already stale.
2. **Retire that vehicle's lapsed holds**, so the unique index and the
   availability query cannot disagree about what is still claimed.
3. **Check for overlaps**, inside the lock. A check performed before the lock
   proves nothing.
4. **Insert.**

Concurrent attempts on the same vehicle serialise at step 1. The second request
waits until the first commits, then re-runs its own check and sees the hold that
was just written.

### The honest caveat

PostgreSQL could express this as an exclusion constraint over a time range —
`EXCLUDE USING gist (vehicle_id WITH =, tstzrange(start_at, end_at) WITH &&)` —
and the database would then refuse an overlapping row no matter what the
application did. That is a structural guarantee.

We are on MySQL, because production is shared hosting. MySQL has no
equivalent. So our guarantee is **behavioural**: it holds exactly as long as
every writer goes through `place()`. Three things defend that:

- The service is the documented single writer, and the model says so too.
- A unique index on `(vehicle_id, start_at, end_at, is_active)` catches an
  identical duplicate at the database level. MySQL ignores NULLs in unique
  indexes, so released holds — which set `is_active` to NULL — drop out of the
  constraint automatically. **This catches exact duplicates only, not partial
  overlaps.** It is a second net, not the mechanism.
- `VehicleHoldConcurrencyTest` runs six real processes at a shared barrier and
  asserts exactly one wins. If someone later adds a second write path, that test
  is what catches it.

If the platform ever moves to PostgreSQL, replace the behavioural guarantee with
the constraint and keep the test.

### The isolation level is part of the mechanism

The MySQL connection runs at **READ COMMITTED**, not InnoDB's `REPEATABLE READ`
default. This is set in `config/database.php` and it is not a performance tweak
— the booking engine is incorrect without it, in two separate ways. Both were
observed in `BookingConcurrencyTest`, not theorised.

**Stale reads.** Under `REPEATABLE READ` a transaction's snapshot is fixed at
its *first* read. Creating a booking reads payment methods, settings, the
vehicle class and the customer before it ever reaches the vehicle lock, so its
view of `vehicle_holds` is already old. Blocking on the lock does not refresh
it. The overlap check therefore consulted a view of the table from before the
winning transaction committed, found nothing, and inserted a second hold over
the same dates. Four of five racing processes did exactly this; only the unique
index stopped them, and it would not have if the ranges had merely overlapped
instead of matching exactly.

**Gap locks.** `REPEATABLE READ` takes next-key locks on range scans. On a
sparsely populated `vehicle_holds` those gaps span most of the index, so
transactions working on entirely different vehicles lock one another out and
deadlock when they insert. That failure is worst when the table is nearly
empty — which is week one of go-live, not week fifty.

**What this does not change:** two customers competing for the same vehicle are
still serialised by the explicit `lockForUpdate()` on the vehicle row. That is
unaffected by isolation level. "We relaxed the isolation level" reads like a
weakening; here it is the opposite.

### Availability results are advisory

`AvailabilityService` answers "which vehicles look free". It does **not**
reserve anything. Between a search result and a hold being placed, another
customer can take the vehicle. Treating an availability result as a promise is
precisely how double-bookings get reintroduced. Only `place()` decides.

---

## 2. Overlap and the turnaround buffer

Hire windows are **half-open**: `[start, end)`. A hire ending at 10:00 and one
starting at 10:00 do not overlap. Closed ranges make every boundary comparison
ambiguous.

Vehicles need time between hires for cleaning, refuelling and inspection — two
hours by default, configurable per vehicle class. This is applied by padding
**both ends** of the requested window before testing it against existing holds:

```
existing.start_at < (requested.end + buffer)
AND existing.end_at > (requested.start - buffer)
```

Padding both ends guarantees at least the buffer's worth of clear time between
any two hires, whichever order they fall in. A hire ending at 10:00 blocks a
new hire starting at 11:00, and permits one starting at 12:00.

`AvailabilityService` groups candidate vehicles by their class's buffer and runs
one query per distinct value. This keeps the date arithmetic in PHP rather than
raw SQL, and keeps those comparisons byte-identical to the single-vehicle check
— which is what stops the two drifting apart. A test asserts they agree.

---

## 3. The two lives of a hold, and why expiry self-heals

A hold means two different things over its life, and `expires_at` carries both.

**While the booking is unpaid** it means "kept back while we wait for money",
and `expires_at` is the payment deadline. If the money never comes, the claim
should evaporate on its own.

**Once the booking is confirmed** that reason is spent and a stronger one
replaces it: the car is spoken for until the customer brings it back.
`PaymentConfirmationService` therefore has `VehicleHoldService::extendToHireEnd()`
move `expires_at` out to the end of the hire.

Without that second step the deadline still lapsed, `stillClaiming()` stopped
matching, and — because both `AvailabilityService` and `place()` decide from
holds and nothing else — the vehicle returned to sale partway through a hire
that had been paid for. It was found in Phase 3 and had been latent since Phase
1: no payment could be confirmed until then, so every booking in the suite sat
in `pending_payment`, where the payment deadline is exactly the right expiry.

`place()` remains the only code that INSERTS a hold. Moving a date on a row that
already exists cannot create an overlap that was not already there, and the
extension only ever moves it later.

### Three things move `expires_at`, and none of them may act alone

By Phase 4 there are three, and the rule they share is more important than any
of them individually: **a hold's expiry is never changed without the reason for
it changing too.**

| Mover | Why | Moves to |
|---|---|---|
| `place()` | The booking is new and unpaid | The payment deadline |
| `extendToHireEnd()` | The booking is now paid for | The end of the hire |
| `extendToDeadline()` | Staff gave the customer longer | The new deadline |

The third exists because a payment deadline is one fact stored in two places —
`bookings.payment_deadline_at` and the `expires_at` backing it. A manager
extending only the first hands the customer another day and simultaneously
releases their car to the next person who searches for it. That is the same
failure as a confirmed booking losing its vehicle, reached by a different route,
which is why `PaymentDeadlineExtensionService` moves both inside one transaction
rather than being an update statement on a column.

Neither extension ever shortens. Both can apply to one booking over its life, in
either order, and neither should be able to undo the other.

### Expiry

A hold still lapses when its payment deadline passes, for bookings that never
got paid. A scheduled sweep releases them and notifies the customer.

The guideline warns that if that job dies unnoticed, vehicles stay locked and
inventory silently disappears. Two mitigations:

- The availability query treats a hold as claiming its vehicle only while it is
  **unreleased *and* not past its deadline**. A lapsed hold stops blocking
  immediately, whether or not the sweep has run.
- `place()` retires that vehicle's lapsed holds inside the lock before checking
  overlaps.

So the worst case of a dead scheduler is stale rows in a table, not vehicles
vanishing from sale. A manual "release expired holds" admin action is still
required, per the guideline.

---

## 4. Money

`DECIMAL(12,2)` in the database, bcmath strings in PHP, at scale 2. Never
`FLOAT`, never `DOUBLE`, and never cast to float in between.

Values arriving from SQL or a form are unscaled — `'300'` rather than
`'300.00'`. Every value is normalised through `bcadd($value, '0', 2)` before it
is used or compared. Skipping that yields arithmetic that is numerically correct
but fails exact string assertions, which is how the problem usually gets
noticed: late, and confusingly.

Tests assert money with `assertSame` on strings, never float comparison.

### The two deposits

The specification calls conflating these the single most likely misreading.

| | What it is | Where it lives |
|---|---|---|
| **Booking deposit** | 50% part-payment of the hire, paid online to secure the booking | percentage in settings; amount on the booking |
| **Security deposit** | Refundable cash taken at the counter against damage | `security_deposit_amount`, per vehicle class |

Different columns, different lifecycles, different UI labels. Keep them apart.

---

## 5. Time

Every instant is stored in **UTC**. Everything is displayed in
**`Africa/Lusaka`** — UTC+2, no daylight saving, which makes the conversion
lossless. Conversion happens at the edge, never in the domain.

The case worth remembering, from the guideline: a booking created at 23:30 CAT
for a pickup the following morning. 23:30 in Lusaka is 21:30 UTC *the same day*.
There is a test for it.

---

## 6. The audit trail

`audit_log` is append-only, and that is enforced by the database:
`BEFORE UPDATE` and `BEFORE DELETE` triggers that raise
`SQLSTATE '45000'` unconditionally. The guideline's reasoning is blunt and
correct — if the ORM can edit it, it will eventually be edited.

The `AuditLogEntry` model *also* refuses updates and deletes. That is
convenience, not the guarantee: it gives a clear domain exception instead of a
raw SQL error, and it fails loudly in development if the triggers were somehow
never installed. The test that matters bypasses the model and issues raw SQL.

There is no `updated_at` column. A row that can be updated is not an audit
record. Correcting a mistaken entry means appending a correcting entry.

### One writer

`AuditLogger` is the only thing permitted to write to `audit_log`, the same
discipline `VehicleHoldService::place()` has over `vehicle_holds` and for a
related reason. Spec §12 lists what every entry must record; with several
writers, half those fields end up populated only sometimes, and a trail that is
usually complete is not a trail. Callers hand it an `AuditEntry` and it decides
what a row contains.

It converts two things centrally rather than at each call site, so they are
converted the same way every time: status enums become their backing strings,
and amounts go through `Money`, so an entry recording `'300'` against a payment
holding `'300.00'` cannot happen.

Write the entry **inside** the transaction that performs the action. An audit
record that survives a rolled-back action describes something that did not
happen.

### Manual or automatic is derived, not declared

§12 requires every entry to record whether a person or a job acted. That is not
an independent fact — it is exactly whether there was an actor, so `AuditLogger`
derives it from one. Passed in separately, an entry could claim a staff member
acted automatically, or that the expiry sweep was a person, and nothing in the
system would object.

`payment_method_code` and `proof_uploaded` were added to the table in Phase 3,
while it was still empty. §12 requires both on every entry and Phase 1 did not
anticipate them. They are columns rather than `metadata` keys because the
questions they answer — show me every cash confirmation, which had proof — are
queries.

---

## 7. Configuration over constants

Every value the business might change — the admin fee, the deposit percentage,
the short-notice threshold, KYC rules, fuel and mileage policy — lives in the
`settings` table, not in a constant. None of them require a deploy to change.

Values the specification does not yet answer are seeded as **placeholders** and
flagged, so they surface in the admin panel and in `OPEN-ITEMS.md` rather than
passing silently as though they had been decided. `SettingsSeeder` uses
`firstOrCreate`, so re-running it never overwrites a real decision with a
placeholder.

---

## 8. The multi-operator seam

The commercial plan is to open the platform to other Zambian operators once it
is proven on the house fleet. So `operators` exists now, and `operator_id` sits
on branches, vehicles and classes from day one.

What deliberately does **not** exist yet: global scopes, per-operator
permissions, commission tracking, any UI. Those belong with the multi-operator
work itself. The point of doing this now is only to avoid a schema migration
across every core table, on live booking data, later.

---

## 9. Layering

```
Console command / HTTP controller / Filament page
        ↓  (thin — no business logic)
Service (bound to a Contract in AppServiceProvider)
        ↓
Eloquent model
```

- Business logic lives in services, never in controllers or models.
- Services are bound to interfaces so callers depend on behaviour, not classes.
- DTOs (`DateRange`) carry validated values across boundaries.
- Models are Eloquent and little else: relations, casts, scopes.
- `declare(strict_types=1)` everywhere; classes are `final` unless there is a
  reason not to be.

---

## 10. Who may do what

Specification §12 is a table of fifteen permissions against three roles. It is
transcribed twice, on purpose: once as `StaffPermission` and `StaffRole`, and
once by hand in `RolesAndPermissionsSeederTest`. The test does not derive its
expectations from the enums, because a test that reads the code it is checking
agrees with that code no matter what the code says.

### The seeder is the authority, not the database

Grants are applied with `syncPermissions()`. Re-running the seeder restores the
§12 matrix exactly and revokes anything granted to those three roles by other
means. The alternative — seeding additively — lets the permission set in a live
database drift away from the reviewed matrix with nothing to notice, and the
direction it drifts is always outward. An operator who needs a different
combination gets a **new role**; they do not get an edited Counter Clerk.

### Where this departs from §12

Five things in the permission model are not in the specification, and all five
are marked in the code where they appear rather than only here.

§12 lists `payments.edit-manual-payment` and `bookings.override-short-notice`
without placing either in its matrix. The first sits at Branch Manager and above
because it changes a figure somebody has already relied on; the second sits at
the counter, because the clerk is the one facing a customer three hours before
pickup and the override exists precisely to save that customer a wait.

`payments.record-manual` is not in §12 at all. Writing down money that arrived
has no permission in the specification, and the nearest one carried the power to
alter payments already recorded. Recording and editing are different acts with
different risks — one states what happened, the other revises it — so they have
different permissions. Counter clerks hold the first and not the second.

`bookings.cancel` and `refunds.disburse` are likewise absent from §12, and were
added later — on 2026-08-08, when refunds first gave the panel a way to end a
hire or hand money back. Both sit at Counter Clerk and above, and both were
briefly answered by accident before they existed: cancelling was gated on
whatever permission the calling screen happened to check, and paying out
borrowed `refunds.approve`. The first meant the true answer to "who may cancel a
booking" was "everybody", discoverable only in a docblock. The second meant a
clerk could hand back a security deposit under §12 but not a refund, across the
same counter, to the same customer.

Neither lets the counter decide anything. A cancellation's refund still needs a
second, more senior person to approve it, and a payout executes an approval
somebody else gave at an amount neither of them can edit.

A specification gap filled by inference is still a gap. These are written down
in three places on purpose: the enum, `OPEN-ITEMS.md`, and here.

### Wildcards are off

`payments.*` would be convenient and is exactly wrong here. §12 separates
confirming cash from confirming a bank transfer because a counter clerk may do
the first and not the second — verifying a transfer means reading a statement
they cannot see. A wildcard erases that distinction silently.

### Permissions are checked in the service, not only at the edge

`hasPermissionTo()` is asserted inside the service that performs the action,
and a policy holds the same matrix for the admin panel to reuse. Authorising
only in a controller or a Filament page means the guarantee lasts exactly as
long as nobody calls the service from a command, a job or a test — and the
expiry sweep is already a command.

Note that `hasPermissionTo()` throws when a permission is absent from the table
rather than returning false. That is wanted: a missing permission row is a
deployment fault, and a silent denial would be read as a role misconfiguration
and sent to the wrong person to fix.

### The permission cache and `WithoutModelEvents`

`PermissionRegistrar` keeps its permissions in memory and reloads them only when
told to, normally by model events. `DatabaseSeeder` suppresses model events for
the whole run. Anything that seeds or checks permissions under that seeder must
therefore not read through the registrar's cache — `RolesAndPermissionsSeeder`
uses direct queries and passes model instances rather than names. The failure
mode otherwise is a `PermissionDoesNotExist` for a permission that is visibly
present in the table. See CHANGELOG.md, Phase 3.

---

## 11. The admin panel is not allowed to be a CRUD panel

Filament's usual idiom is a resource per table, with create and edit forms that
write straight to the model. **That idiom is incompatible with this codebase**,
and the incompatibility is not stylistic.

Three phases put every dangerous write behind a service. `place()` owns holds
because it holds the lock. `PaymentConfirmationService` owns confirmations
because the unique key behind it is what stops money being counted twice.
`BookingStateMachine` owns statuses because it is the only thing that knows
which moves §7.3 permits. `AuditLogger` owns the trail because §12 requires
every entry to carry the same fields.

A generated `BookingResource` with an editable `status` dropdown bypasses all
four in one click, and nothing would fail — the row would simply be wrong.

So:

- **Bookings, payments and `audit_log` get read-only resources.** No create
  form, no edit form. Every mutation is an explicit Filament Action that calls
  the service and lets its domain exception surface as a notification.
- **Only genuinely CRUD-shaped things get forms**: vehicles, classes, branches,
  payment methods, settings, users and roles. Even these go through
  `PricingService`-safe columns rather than raw rate edits.

This is more work than `make:filament-resource`, and it is the reason Phase 4 is
built rather than generated.

### The policy is what enforces it

`BookingPolicy` returns false for `create`, `update` and `delete`. Filament
reads policies to decide which controls to render, so this both hides those
buttons and refuses them if they are reached another way.

That distinction is the whole point. A rule kept in a docblock survives exactly
as long as everyone reads the docblock; a rule in a policy survives somebody
running `make:filament-resource Booking` next year and wiring up the form it
generates. The resource also declares only `index` and `view` pages, so the
routes do not exist either — and there are tests for both.

Apply the same shape to `payments` and `audit_log` when those resources arrive.

### The panel gate is not the permission model

`User::canAccessPanel()` asks one question: is this person staff at all. It
fails closed on an unrecognised panel id and on a user holding no role the
`StaffRole` enum knows about — a role created in the admin panel to group a few
permissions is not a way in.

What somebody may then *do* is decided per action by §12's permissions, which
are enforced in the services. Filament's default is that authentication
suffices; its own contract file warns that without `FilamentUser`, every
authenticated user reaches the panel outside `local`. The gate exists so that
"has a password" and "may see every payment in the business" are different
statements.

---

## 12. Money that has arrived, and money that is expected

### Confirming a payment is an INSERT, not an UPDATE

Spec §12 requires that duplicate confirmation of a payment be *structurally*
impossible rather than merely discouraged, and the developer guideline names the
cause it has in mind: a double-clicked confirm button.

The obvious design is `confirmed_at` and `confirmed_by_user_id` on the payment
row. It cannot meet that requirement. Confirming twice is then an UPDATE, and no
index in any database refuses a second UPDATE. The strongest available guard is
to read the row, see it is already confirmed and decline — an application check,
and application checks lose races. Two staff members hitting confirm in the same
instant both read "not yet confirmed", and both write.

So confirmation is a row in `payment_confirmations`, with a **unique key on
`payment_id`**. The second writer gets a constraint violation however the race
falls out, and regardless of what any future caller forgets to check.

`PaymentConfirmationService` still takes a lock and checks first. That is
courtesy, not the mechanism: it produces "already confirmed by Mary at 14:32"
rather than a raw SQL error. The index is the guarantee, and a two-process test
proves it under real contention.

This is the same argument as §1, reached the other way round. There, MySQL could
not express the constraint we wanted, so the guarantee had to be behavioural and
is defended by a test. Here it can, so it is structural — and where the database
can hold the rule, it should.

### Three levels of payment state, not one

Spec §7.1 gives a single list of payment states, but they sit at two different
levels. `proof_submitted` describes one receipt. `partially_paid` describes a
booking — an individual K500 cash payment is confirmed or it is not, and is
never "partially paid".

Modelled as one enum on the payment row, confirming a balance payment would have
to reach back and rewrite the earlier deposit row from `partially_paid` to
`paid_in_full`. A row's state would then depend on rows it knows nothing about,
and settled history would acquire a second writer.

So there are three:

| | What it answers |
|---|---|
| `BookingStatus` (§7.2) | Where is the booking? |
| `BookingPaymentStatus` (§7.1) | How much of the hire has been paid? |
| `PaymentStatus` | What happened to this one receipt? |

Spec §7 opens by saying booking states and payment states are separate entities
that must not be merged. This is that instruction applied once more, one level
further down. `BookingPaymentStatus` carries §7.1's values verbatim, so nothing
in the specification is lost by the split.

`BookingPaymentStatus` is **derived, never assigned**. It is recomputed from the
sum of confirmed receipts in the same breath as `amount_paid` and `balance_due`,
so the three cannot drift apart.

### Expected, arrived, and the gap between them

`payments.amount` is what actually arrived. `payments.expected_amount` is what
was asked for when the receipt was raised. A shortfall is the difference.

The booking's `balance_due` cannot stand in for `expected_amount`, because it
moves as other payments are confirmed: the same short payment would look
different depending on when the question was asked. Recording the expectation
alongside the receipt freezes the comparison at the moment it was made.

An unmatched receipt has no expectation and therefore cannot be short. It is not
missing money; it is money nobody has attributed yet.

### Two thresholds, and they are not the same

`payment_status` measures against the whole hire. Anything short of the grand
total is `partially_paid`.

Whether the **booking** confirms measures against what the customer chose to pay
now — the full total, or the deposit. Somebody who opted for a 50% deposit and
paid it has done everything asked of them, so their booking confirms while their
payment position stays `partially_paid`. That combination is spec §5 working
correctly, not a contradiction: enough to hold the car, not enough to release it.

Somebody who sent less than they chose has not met the threshold. The booking
stays in `pending_payment` with its hold and deadline untouched, so they still
have until the deadline to send the rest. Cancelling them for underpaying, while
their money sits in the operator's account, would be indefensible.

Confirmation never assigns a booking status. It recomputes the balance, works
out what the booking is entitled to be, and asks `BookingStateMachine` whether
that move is permitted — which is where the cross-border rule lives, so a
cross-border booking goes to `awaiting_cross_border` rather than `confirmed`
without this service knowing why.

### Not every booking may be paid

`BookingStatus::canAcceptPayment()` gates both attributing a receipt to a
booking and confirming one. Only `pending_payment`, `confirmed` and
`awaiting_cross_border` accept money.

`confirmed` has to be on that list: it is how the balance is settled at the
counter before the keys are handed over, which is the normal path for a booking
that paid a 50% deposit.

Everything else refuses. A cancelled booking is the case that matters — a
customer pays late, a clerk matches the receipt in the morning to a booking the
sweep cancelled at 23:00, and without this guard the confirmation would succeed:
balance recomputed, booking still cancelled, no refund record, the money visible
only to somebody reading the payments table. Refusing at attribution as well as
at confirmation keeps the receipt in the unmatched queue, where it can still be
traced, rather than attached to a booking nobody will honour.

### Unpaid is not underpaid

`hasShortfall()` is false when nothing has arrived. Every receipt starts at zero
against its full expected amount, so a literal comparison would report every
unpaid booking as short by its entire total, and the queue that exists to catch
customers who sent too little would be full of customers who have not sent
anything. Those need different responses — one is chased, the other reconciled.
An unmatched receipt is likewise never short: with no booking, nothing was ever
asked for.

### What the adapter interface is and is not

Spec §4 requires every provider to be reached through one interface so that a
gateway can be added later without touching checkout or booking logic. The
interface therefore carries only what the four offline providers genuinely
answer differently: what the operator must configure, whether a person must
confirm, and what the customer is told.

There is no `charge()` and no webhook handling, because the guideline forbids
stubs beyond the interface and a wider interface would mean four classes of
`throw new NotImplementedException` — worse than no interface at all. The
interface widens when a real gateway exists to check it against.

The card methods have no adapter, and asking for one raises. That is the correct
answer rather than a gap: an adapter that resolves cleanly and does nothing is
how a card payment eventually appears to have been taken.

### `amount_paid` is recomputed, never incremented

Adding to a running total is wrong the first time anything is confirmed twice,
corrected, or replayed — and it is wrong silently, because the number still
looks plausible. The total is always the sum of the receipts that count, which
can be recomputed from scratch at any time and checked against the till.

Two mechanical traps live in that sum, both of which have cost time on previous
projects:

- Aggregates through a relation inherit its ordering, and MySQL rejects a
  `SELECT SUM(...)` carrying an `ORDER BY` on a column outside the aggregate
  (error 1140) where SQLite passes it silently. Call `->reorder()` first.
- SQL returns `'1655'`, not `'1655.00'`. Normalise through `Money::of()` before
  comparing or storing, or exact-string assertions fail in ways that look like
  arithmetic bugs.

Both traps now live in one place: `BookingLedger`. See §13.

---

## 13. Money going back

Spec §9. Everything above is about money arriving; this is the other direction,
and it is not the same problem reversed.

### A refund is a ledger entry, not an undo

The tempting design flips the original receipt to `refunded` and stops there. It
is wrong, and quietly so. A confirmed payment records that money genuinely
arrived, on a date, verified by a named person against a bank line. **That
remains true after a refund.** Rewriting the row to say otherwise destroys the
only record of an event that still happened, and leaves the month it fell in
impossible to reconcile against a statement.

So payment rows keep their status forever, and:

```
amount_paid = SUM(confirmed receipts) − SUM(disbursed refunds)
```

`BookingPaymentStatus::RefundPending` and `Refunded` carry §7.1's refund
vocabulary at the booking level. `PaymentStatus::RefundPending` and `Refunded`
exist on the receipt enum and go **unused** at MVP — they are the merged reading
this design rejects.

### `BookingLedger` owns that sum, and nothing else may

Two services change what a booking has been paid: confirming a receipt adds to
it, disbursing a refund takes from it. Written twice, the two implementations
agree right up until one of them is edited — and the failure is not an exception,
it is a booking whose stated balance depends on which service touched it last.

`PaymentConfirmationService` used to compute this itself. It no longer does.

**Approved is not disbursed.** Only refunds that have actually been paid out are
subtracted. An approved refund is money still in the operator's hands, and
treating it as gone would show the customer a balance they do not owe.
`PaymentStatus::countsTowardsAmountPaid()` takes the same position from the other
side; the two must not drift.

### The figures are frozen, for the same reason `expected_amount` is

`amount_paid_at_request`, `booking_deposit_retained`, `admin_fee_configured`,
`admin_fee_deducted` and `amount` are all snapshots taken when the refund was
raised. The admin fee is a setting somebody can change this afternoon;
`amount_paid` moves whenever another receipt is confirmed; and whether a
cancellation fell inside 24 hours of pickup **stops being true a day later**. A
refund recomputed at approval time would be a different number from the one the
requester saw and the customer was told, with nothing to show which was meant.

`RefundCalculator` is a pure function of `(booking, reason)` and writes nothing,
which is what lets the panel quote live in a modal — staff are usually on the
telephone to the customer — and what lets §9's rules be tested exhaustively
without cancelling anything.

Deductions apply in §9.1's own order: **deposit first, then the fee on what is
left.** Both clamp at zero. §9 describes money withheld from a sum already held;
it never describes billing somebody for cancelling, so a customer who paid K100
against a K150 fee gets nothing back and is not invoiced for K50.

### Two structural guarantees, both familiar

**The two-person rule (§9.3)** is enforced by `RefundRequestService::approve()`
*and* by a `CHECK` constraint refusing `approved_by_user_id = requested_by_user_id`.
The duplication is deliberate: this is a fraud control, not a workflow nicety.
One person who can both raise and approve a refund can move money out of the
business alone, and the audit trail would show it as properly authorised. A
control that lives only in application code is one careless method away from
being absent.

**Never disbursed twice (§9.3)** is the unique key on
`refund_disbursements.refund_id` — the same argument as `payment_confirmations`
in §12, and the stronger case of the two. A duplicated confirmation overstates
what a customer paid and can be unpicked from the records; a duplicated payout is
cash that has physically left the building. `RefundDisbursementService` locks and
checks first, but that is courtesy: it produces "already paid out by Mary at
14:32, reference MM-4471" rather than a raw constraint error.

Laravel's `Blueprint` has no `check()` helper, so the constraint is raw
`ALTER TABLE` in the migration.

### Cancelling and refunding are separate, and joined only at the panel

`BookingStateMachine` answers questions and never performs a transition, so
`BookingCancellationService` exists to actually write the status, stamp the time,
release the vehicle hold and record who decided. `RefundRequestService` raises the
refund. **Neither knows the other exists**; `CancelAndRefundAction` calls both in
one transaction.

That separation is not tidiness. A cross-border cancellation is the operator's
failure and a customer cancellation is theirs; a booking can be cancelled with
nothing to refund; and refunds will eventually be needed for reasons that are not
cancellations. Fusing them now would have to be unpicked later.

**Cancelling releases the hold.** `claimsVehicle()` is false for every cancelled
state, so leaving it would keep the car off sale until the original hire ended —
no exception, no wrong number on a screen, just a vehicle that stops appearing in
searches. That is why it survived three phases as an open item.

### A refund of zero is not recorded

A late cancellation can forfeit exactly what was paid. Creating a row for it
would put a payment that will never be made into the approval queue, and it could
never be disbursed — §9.3 requires a disbursement reference, and there is none
for money that did not move. The booking is still cancelled, and the calculation
survives in the cancellation's audit entry.

### Where this departs from §12, and why it is written down

Two things here have no permission in the specification, and both are recorded
in `OPEN-ITEMS.md` rather than settled quietly.

§12 defines no permission for **cancelling a booking**, so
`BookingCancellationService` asserts none — a departure from §9 of this document,
which says services check their own permissions. Inventing `bookings.cancel`
would be a fourth undocumented departure from §12. Authorisation currently sits
with the only caller, gated on `refunds.request`.

§12 defines no permission for **handing the money over** either. Both were
settled on 2026-08-08 as `bookings.cancel` and `refunds.disburse`, Counter Clerk
and above. See §10.

---

## 14. Undecided is not zero

Spec §15 lists twelve figures only the business can answer. Two mechanisms hold
them, and the difference between the two is worth understanding before adding a
third.

### Settings carry a flag

`settings.is_placeholder` marks a value as seeded rather than chosen. The value
is real and in use — the platform reads it exactly as though it had been decided
— and the flag is what surfaces it in the admin panel and keeps
`OPEN-ITEMS.md` honest.

The subtle part is what clears it. `SettingsRepository::set()` defaults
`$isPlaceholder` to false, so **calling it clears the flag**. `ManageSettings`
therefore compares each submitted value against what is stored and calls `set()`
only for the ones that actually changed. A save-everything loop would mark all
seventeen settings as decided the first time anybody pressed Save, silencing
every warning in one click — which is worse than never having flagged them,
because the flags are the only thing that says which figures are still guesses.

Both sides of that comparison are normalised per setting type first. A money
field filled with `'0.00'` comes back from the form as `'0'`, and a spurious
"changed" clears a flag just as effectively as a real one.

### Vehicle class pricing carries a null

Three §15 items live on `vehicle_classes` rather than in `settings`, and they
originally had no way to say "undecided": `DECIMAL(12,2) NOT NULL DEFAULT 0`
made an unanswered figure and a deliberate zero the same value.

That is worse than an unflagged setting, because these are customer-facing.
Spec §6 requires the security deposit to appear in search results, at checkout,
in the confirmation email and in the T&Cs, and says it **must never first appear
at the counter**. A class left at the default did not warn anybody — it
published "no deposit required" to every customer who looked, and the counter
asked for K2,500 on collection. §10 does the same for the excess: zero states
that the customer is liable for nothing.

So the columns are nullable, and **null means undecided while 0.00 means decided
and zero**. An operator who genuinely wants a zero-deposit class can say so.

Three things then follow:

- `PricingService` **refuses** a null rather than treating it as zero.
- `AvailabilityService` **withholds** an incomplete class from search entirely.
  This is the protection; the exception is a backstop for the other ways into
  the booking engine.
- The form **does not require** the three fields. Making them mandatory would
  produce exactly the invented figure the null exists to prevent — a zero typed
  to satisfy validation, and a class quietly sellable on a number nobody chose.

A vehicle carrying its own `security_deposit_amount` override rescues its class,
because the figure shown to the customer is then the vehicle's and it is a real
decision. The excess has no vehicle-level override, so an undecided excess
withholds every vehicle in the class.

### Payment methods: configured is a third kind of "not ready"

`payment_methods.account_details` is JSON, and each adapter declares what it
needs — `BankTransferAdapter` wants a bank name, an account name and an account
number. Until those exist the method is switched on in name only: the
instructions merge to blanks where the account number belongs, and a customer is
told to send money nowhere.

Since 2026-08-09 such a method is **withheld from checkout**. The gate is on
`PaymentMethodService` — `selectableFor()` and `assertSelectable()` — and
deliberately **not** on `PaymentMethod::isOfferable()`.

That distinction is the whole design. `isOfferable()` is asked by staff-facing
code too: `CounterPaymentService` and the panel's take-payment action both use
it, and a bank transfer that has *already landed* must still be recordable at
the counter. The money arrived whether or not anybody has typed an account
number into the panel, and refusing to write it down would be strictly worse
than the problem being solved.

So: the customer cannot be offered a method they could not pay by, and staff can
still record money that has come in. Cash requires nothing, which is what keeps
a fresh production install — where every set of details is deliberately empty —
able to take bookings at all.

### The limit of both mechanisms

Neither can recover information that was never recorded. Rows already sitting at
the old zero default read afterwards as deliberate zeros, and no migration can
distinguish them. The panel counts classes still holding a null; it cannot flag
a zero that was never really chosen. **Review the fleet before go-live** rather
than trusting the badge to be complete.
