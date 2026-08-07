# Changelog

Newest first. Each entry covers one phase of the build order set out in the
developer guideline §3, or one slice of a phase still in progress.

---

## Phase 4 — Admin panel: the access gate · 2026-08-05

Filament installed, and the front door locked before anything was put behind it.
No screens yet, deliberately.

### Added

- `filament/filament ^5.7`. Verified against Filament's own `composer.json`
  rather than its marketing pages: both v4 and v5 accept
  `illuminate/contracts ^11.28|^12.0|^13.0`, so the real choice was Livewire 4
  (v5) against Livewire 3 (v4). With no Livewire code in the project there was
  no migration cost either way, and Tailwind v4 and Vite were already in
  `package.json`, so v5 is the generation this stack is already aligned with.
- `AdminPanelProvider` at `/admin`.
- `User::canAccessPanel()`, backed by the `FilamentUser` contract.
- `DemoStaffSeeder` — one account per role, local only.
- `AdminPanelAccessTest` — 8 tests, including two over HTTP.

**Tests** — 354 passing, up from 346.

### Decisions

- **The panel is the first thing built, and it is a gate rather than a screen.**
  Filament's own contract file warns that without `FilamentUser`, "all
  authenticated users can access your panel when APP_ENV is not local".
  Authentication is not authorisation, and Phase 3 spent its length
  establishing that §12 grants permissions per action and per payment method. A
  panel that showed every booking and payment to anyone holding a password
  would have made that work beside the point.

- **The gate asks only "are you staff".** What somebody may then do stays with
  the per-action permissions. Front door, not the whole building. It fails
  closed twice: an unrecognised panel id is refused, and so is a user holding no
  role the enum recognises — so a role invented in the admin panel to group a
  few permissions is not a way in.

- **Bookings, payments and the audit log will get READ-ONLY resources.**
  Recorded in the panel provider's docblock and in ARCHITECTURE §11 before any
  resource exists, because the moment one is generated the default is a form
  that writes straight to the model — and an editable `status` dropdown bypasses
  `BookingStateMachine`, `VehicleHoldService`, `PaymentConfirmationService` and
  `AuditLogger` in a single click. Every mutation will be an explicit action
  calling the service.

- **`FilamentInfoWidget` was removed from the dashboard.** It reports the
  installed Filament version to anyone who reaches the panel, which is free
  reconnaissance on something that handles payments.

- **`DemoStaffSeeder` seeds a deliberately roleless account.** Signing in as
  `nobody@carhire.test` should be refused, and seeding it makes that checkable
  in a browser rather than only in a test. The seeder throws outside `local`
  rather than trusting `DatabaseSeeder`'s guard, because it creates a super
  admin with a known password.

### Fixed during the slice

- The panel was generated as `james` at `/james` — the install prompt took a
  name rather than the panel id. Renamed to `AdminPanelProvider` at `/admin`
  before anything was built on it, since a panel id ends up in auth redirects,
  `Filament::getPanel()` lookups, tests and deployment notes. A test asserts
  `/james` now 404s, so the rename cannot leave a second route quietly serving
  the same panel.

---

## Phase 3 — Pre-merge audit fixes · 2026-08-05

Findings from a review of the payments work before merging to master. One of
them would have taken a customer's money and left nothing saying so.

346 tests passing, up from 338.

### Fixed

**HIGH — money could be confirmed against a cancelled booking.** `matchToBooking()`
checked the payment but never the booking, and `confirm()` checked the payment's
status but never the booking's. So an unmatched receipt could be attached to a
booking the sweep cancelled overnight and then confirmed: `amount_paid`
recomputed, `balance_due` reduced, the booking left cancelled, no refund record
anywhere. The customer's money would have existed only as a row in `payments`.

The path is ordinary, not contrived — a customer pays late and a clerk matches
the receipt in the morning to a booking that expired at 23:00.

Closed with `BookingStatus::canAcceptPayment()`, enforced at both attribution
and confirmation. `Confirmed` still accepts payment, because that is how the
balance is settled at the counter before release; `VehicleReleased`,
`Completed` and every cancellation refuse it.

**MEDIUM — the permission was checked against an unlocked copy of the payment.**
`assertMayConfirm()` ran once, before the transaction, against the caller's
instance. The permission depends on `payment_method_code`; nothing can change
that today, but `payments.edit-manual-payment` exists and Phase 4 will build the
screen behind it. Now re-asserted against the locked row. The pre-flight check
stays, so a refusal still takes no locks.

**LOW — the sweep's `leftForStaff` counter conflated three outcomes.** Every
skipped candidate incremented it, including bookings that had simply been paid.
That figure is printed nightly and DEPLOYMENT.md tells somebody to read it, so a
number that cries wolf is worse than no number. Now three outcomes; only "holds
money" counts.

**LOW — `extendToHireEnd()` extended only the newest hold.** A booking should
have one unreleased hold, but a reassignment failing partway could leave two,
and the un-extended one would lapse and put a still-claimed vehicle back into
search results. Extends all of them now.

**LOW — a duplicate query ran while row locks were held.** `alreadyConfirmed()`
resolved the confirming user twice inside the transaction.

### Two caveats recorded honestly

The audit was inspection by the same person who wrote the code, so its failure
mode is missing what was never considered in the first place. It is not a
substitute for a second pair of eyes.

The two race-dependent fixes are reasoned rather than demonstrated: a single
process cannot stage a confirmation landing between the candidate query and the
lock, which is the same limitation already noted on the expiry race test. The
tests added alongside them say so rather than implying coverage they do not
have.

---

## Phase 3 — Permission decisions settled · 2026-08-05

The three §12 gaps this phase accumulated, resolved with the operator rather
than left flagged. No schema change; a seeder change and one new permission.

### Added

- `payments.record-manual` — **not in spec §12.** §12 has no permission covering
  the act of writing down money that arrived, and the nearest one,
  `payments.edit-manual-payment`, carries the power to alter payments already
  recorded. Guarding recording with it forced a choice between two wrong
  answers: let counter clerks change recorded figures, or stop the people
  standing at the till from writing money down as it arrives. The second is
  worse — a receipt that waits for a manager is a receipt on a note beside the
  till, which is where money goes missing. Splitting them keeps "record what
  arrived" and "change what was already recorded" as separate powers, which is
  the distinction that matters when somebody later asks who could have altered a
  figure.

### Changed

- **Counter clerks may now record and attribute receipts.** They hold
  `payments.record-manual`; they still do not hold
  `payments.edit-manual-payment`.
- **`bookings.override-short-notice` moved to Counter Clerk.** §12 lists it
  without saying who holds it, and it had been placed at Branch Manager by
  default. The clerk is the one facing a customer standing in front of them
  three hours before pickup, and sending them away to find a manager is exactly
  the friction spec §8.2's override exists to remove.
- `payments.edit-manual-payment` stays at Branch Manager and above. It changes a
  figure somebody has already relied on.

OPEN-ITEMS.md now records these as settled decisions with their reasoning rather
than as outstanding questions.

---

## Phase 3 — Expiry sweep · 2026-08-05

The last slice. An unpaid booking now cancels itself.

**Phase 3 is complete.** A booking can be taken, priced, held, paid for,
confirmed and expired, and every consequential step of that is audited.
338 tests passing, up from 187 at the end of Phase 2.

### Added

- `BookingExpiryService` and `carhire:expire-bookings`, scheduled every five
  minutes in `routes/console.php`.
- `ExpirySweepResult` — counts, not a void return. The guideline is explicit
  that a dead expiry job is one of the ways this platform fails quietly, and a
  run that reports its numbers can be watched.
- `Booking::scopeStalledAfterDeadline()` — the queue of part-paid bookings the
  sweep deliberately refuses to touch.

### Decisions

- **One transaction per booking, not one per sweep.** A single transaction
  around the whole run would hold locks on every expiring booking until the last
  one finished, block staff confirming any of them, and lose the entire run to
  one bad row.

- **Part-paid bookings are left for staff.** Spec §8.4 says a lapsed deadline
  cancels the booking, and plainly assumes nothing was received — but a customer
  can now confirm less than they chose to pay, which leaves the booking pending
  and holding their money. Cancelling that unattended would strand real cash
  against a cancelled booking with no refund record, and spec §9.3 wants two
  people on any refund, neither of whom is a cron job. Confirmed with the
  operator before building it.

- **The candidate list is a list of suggestions.** Every condition is re-checked
  under the booking's own lock. A staff member confirming at 14:59:59 and the
  sweep running at 15:00:00 are the same booking touched twice a heartbeat
  apart, and acting on the candidate query's answer would cancel a booking that
  had just been paid for.

- **Five minutes, not hourly.** A lapsed deadline is a claim on a vehicle. An
  hourly sweep keeps a car off sale for up to an hour after the claim on it
  ended, which on a small fleet is a booking lost for nothing.

- **Not `runInBackground()`.** The sweep is short, and running it inline puts a
  failure in the scheduler's own exit status where monitoring can see it. The
  cron line in DEPLOYMENT.md logs to a file rather than `/dev/null` for the same
  reason.

### One test is deliberately labelled as weaker than it looks

`test_a_booking_confirmed_before_the_sweep_is_not_a_candidate` proves the
candidate query excludes an already-confirmed booking. It does **not** prove the
re-check under the lock, because a single process cannot stage a confirmation
committing after the candidate query has run and before the cancellation takes
its lock. The limitation is written into the test rather than left for a green
tick to imply coverage that is not there. Proving it wants a fourth
multi-process harness; the three that exist each found a real bug, so that is
not an idle suggestion.

### Known gaps carried into Phase 4

- Reminder notifications (spec §8.4, 25% of the window remaining) are not built.
  Notifications are Phase 6.
- The part-paid queue has no screen. Until it does, the command's warning line
  is the only thing that will tell anyone those bookings exist.

---

## Phase 3 — Payment confirmation · 2026-08-05

Money becomes real. A booking can now be paid for and confirmed.

### Added

**Domain**
- `PaymentConfirmationService` — the only thing in the platform that moves a
  booking forward on the strength of payment, and the sole writer to
  `payment_confirmations`.
- `PaymentConfirmationResult` — the recomputed figures, so a confirmation screen
  shows what the action produced rather than whatever the row says by the time
  it renders.
- `PaymentRecordingService::matchToBooking()` — attributing an unmatched receipt.
- `VehicleHoldService::extendToHireEnd()` — see the bug below.
- `carhire:attempt-payment-confirmation`, the third concurrency harness.

**Tests** — 322 passing, up from 288, including a third multi-process suite.
The run is now around four minutes.

### The bug this chunk found

**A confirmed booking's vehicle went back on sale partway through the hire.**

A hold is created with `expires_at` set to the payment deadline, because until
the money arrives that is all the claim is worth. Nothing moved that date when
the booking was confirmed. Once the deadline passed, `stillClaiming()` stopped
matching — and both `AvailabilityService` and `VehicleHoldService::place()`
decide from holds and nothing else. The car was free to be sold again, to
somebody who would turn up and find it gone.

It had been latent since Phase 1 and nothing could have caught it: no payment
could be confirmed until this chunk, so every booking in the suite sat in
`pending_payment`, where the payment deadline is exactly the right expiry.

Fixed by giving the hold its second life explicitly — on confirmation,
`expires_at` moves out to the end of the hire. Two tests cover it, one of which
travels past the old deadline and asserts the vehicle is still claimed.
ARCHITECTURE §3 is rewritten around it.

### Decisions

- **The unique key is the guard; the check is courtesy.** The service locks and
  re-reads before inserting, but that only exists to produce "already confirmed
  by Mary at 14:32" instead of a raw constraint error. Both paths raise the same
  exception on purpose — staff should not be able to tell which one caught them.
  `PaymentConfirmationConcurrencyTest` runs four processes at a barrier against
  one payment and asserts one confirmation, one audit entry, and an
  `amount_paid` of 1155.00 rather than 4620.00.

- **Lock order is booking, then payment, everywhere.** `confirm()`,
  `matchToBooking()` and `PaymentReferenceGenerator` all take them in that
  order, so transactions holding both queue rather than deadlock. Reversing it
  in any one of them would introduce a cycle.

- **Two thresholds, deliberately different.** `payment_status` measures against
  the whole hire; whether the booking confirms measures against what the
  customer chose to pay. A paid deposit therefore confirms the booking and
  leaves the hire `partially_paid` — enough to hold the car, not enough to
  release it. An underpayment leaves the booking pending with its hold and
  deadline intact, because cancelling somebody for underpaying while their money
  sits in the operator's account would be indefensible.

- **The balance is clamped at zero.** An overpayment is a refund question, not a
  debt owed the other way, and a balance of −190.00 on a screen invites somebody
  to treat it as credit against a future hire. The overpaid amount is reported
  separately.

- **Confirmation never assigns a booking status.** It recomputes, decides what
  the booking is entitled to be, and asks `BookingStateMachine`. That is where
  the cross-border rule lives, so a cross-border booking reaches
  `awaiting_cross_border` without this service knowing why (spec §7.3, §11).

- **The amount received is a required argument.** Spec §5 and the guideline both
  anticipate customers sending the wrong figure. A confirm button that defaults
  to the expected amount is a button that records money nobody counted.

- **A matched receipt keeps its `UP-` reference and gains no expected amount.**
  Renumbering it would erase the only reference the customer and the statement
  have in common; back-filling an expectation would invent a shortfall out of a
  figure the customer was never quoted.

- **Attribution never confirms.** Working out whose money this is and verifying
  that it arrived are two judgements, and having just made the first should not
  make the second happen by itself.

### Known gaps

- Nothing expires an unpaid booking yet. The sweep and its scheduled command are
  the last slice of this phase.
- A hold now claims its vehicle until the hire ends, but nothing releases it
  when a car comes back early. Recorded in OPEN-ITEMS.md.

---

## Phase 3 — Payment adapters and recording · 2026-08-05

A booking now produces a real payment record. Nothing confirms one yet.

### Added

**Domain**
- `PaymentAdapterContract` and four offline implementations — cash, bank
  transfer, MTN, Airtel. Spec §4's "common adapter interface so a gateway can
  later be added without touching the checkout UI or booking logic".
- `PaymentAdapterResolver` — the one place a method code becomes behaviour.
- `PaymentRecordingService` — raises the receipt a booking waits on, and
  records money that arrived without one.
- `StaffPermissionDeniedException`, `PaymentNotRecordableException`.

**Changed**
- `BookingCreationService` raises a payment inside its existing transaction, and
  `BookingCreationResult` gained a non-nullable `payment`. Spec §14.3 requires
  every offline booking to produce a payment record and a unique reference,
  short-notice bookings included — no hold is placed for those, but the customer
  still has something to pay and a number to quote when they walk in.

**Tests** — 288 passing, up from 253.

### Two bugs the tests caught

Both were the implementation disagreeing with its own docblock, which is the
useful kind of disagreement to find in a test run rather than in a queue.

**A freshly raised receipt reported itself as short by the whole booking.**
`hasShortfall()` compared `amount` against `expected_amount` literally, and a
receipt starts at zero against the full total. Every unpaid booking would have
appeared in the queue meant for customers who sent too little. Unpaid is chased;
underpaid is reconciled — different problems, and the queue for the second is
useless when it is full of the first. A receipt with nothing against it is no
longer short; one ngwee in, it is.

**A missing account detail rendered as `:account_number` on the customer's
screen.** The merge map only carried keys that existed. Required details are now
seeded as empty strings first, with real values overwriting them. Deliberately
narrow: a placeholder the adapter does not declare is left exactly as written,
because stripping anything shaped like `:word` means guessing at operator copy,
and guessing wrong deletes text from a customer's instructions with nothing to
show it happened. There is a test on each side of that line.

### Decisions

- **The adapter interface carries only what the four offline providers genuinely
  answer differently.** No `charge()`, no `redirectUrl()`, no webhooks. The
  guideline says not to build stubs beyond the interface, and a wider interface
  would mean four classes of `throw new NotImplementedException`, which is worse
  than no interface at all. When a gateway arrives, that is the moment to widen
  it — with a real implementation in hand to check it against.

- **Card methods have no adapter, and asking for one is refused.** A stub would
  resolve cleanly and do nothing, which is how a card payment comes to look as
  though it had been taken.

- **MTN and Airtel are separate classes despite behaving identically today.**
  They are separate businesses with separate merchant numbers, separate
  statements and separate reconciliation. The first to gain an API needs its own
  adapter regardless, and splitting one class in two at that point is a worse
  job than having two now. They do share one §12 permission, because the access
  needed to verify either is the same.

- **Recording never touches the booking's payment position.** `amount_paid`,
  `balance_due` and `payment_status` are recomputed together from confirmed
  receipts only. A convenient update from the recording service would be exactly
  the second writer that makes that guarantee untrue.

- **Money is formatted for customers without ever becoming a float.**
  `number_format()` takes one, so instructions carry no thousands separators —
  "ZMW 1155.00". A customer copying a figure into a banking app does not want
  commas in it anyway.

- **A customer's own checkout records as `is_automatic = true`.** `audit_log`
  has `actor_user_id` and a manual/automatic flag, and customers are not users,
  so there is no third category. "No member of staff acted" is the honest
  reading and the one §12 is really asking about — who on the payroll is
  accountable.

- **Keying in a payment requires `payments.edit-manual-payment`.** §12 has no
  "record a payment" permission; that is the nearest, and it means counter
  clerks cannot fill the unmatched queue. The alternatives are worse: any
  authenticated user, or nobody at all. Third such judgement call, all three
  logged in OPEN-ITEMS.md.

### Known gaps

- Nothing confirms a payment yet, so no booking has ever moved out of
  `pending_payment` by paying.
- An unmatched receipt can be recorded but not yet attributed to a booking.
- `account_details` is empty for every method, so bank and mobile money
  instructions render with blanks where the account and merchant numbers belong.
  Operator data, entered through the admin panel; now tracked in OPEN-ITEMS.md.

---

## Phase 3 — Audit writing and payment references · 2026-08-05

The two things every remaining service in this phase depends on.

### Added

**Schema**
- `audit_log.payment_method_code` and `audit_log.proof_uploaded`. Spec §12
  requires both on every entry; Phase 1 built the table without them. Added now
  because the table is still empty — `AuditLogger`, its first writer, arrives in
  this same commit — so it is the last moment this costs nothing.

**Domain**
- `AuditAction` — the audited actions of spec §12.
- `AuditEntry` — one thing that happened, on its way to the log. Deliberately
  dumb; it converts nothing.
- `AuditLogger` — the sole writer to `audit_log`, which has been append-only
  and unwritten-to since Phase 1.
- `ReferenceSequence` — the locked, gapless counter, extracted from
  `BookingReferenceGenerator` so payments can share it.
- `PaymentReferenceGenerator` — `BR-00001-1` for a booking's payments,
  `UP-00001` for money that arrived without one.

**Tests** — 253 passing, up from 234.

### Decisions

- **`is_automatic` is derived from whether there is an actor, never passed in.**
  §12 wants manual-versus-automatic recorded, but that is not an independent
  fact — it is precisely whether a person did it. As a separate flag, an entry
  could claim a staff member acted automatically, or that the expiry sweep was
  a person, and nothing would object.

- **`AuditAction` declares every §12 audited action, including ones nothing
  writes yet** — refunds, KYC, cross-border, payment method changes. Adding
  cases phase by phase is how an audit table ends up holding
  `payment.confirmed`, `payment_confirmed` and `confirm_payment`, at which
  point querying it honestly becomes guesswork. The vocabulary is cheap;
  agreeing on it afterwards is not.

- **A payment's suffix is derived from existing payments, not from a counter
  row.** Booking references need a counter because they are global and gapless.
  A suffix is neither — it is per booking and already implied by the payments
  that exist, so a counter would be a row per booking to maintain and a second
  source of truth to disagree with them. Deriving it makes allocation a
  read-modify-write, so it takes a lock on the **booking** row: there is no row
  to lock for a payment that does not exist yet, and locking the booking gives
  every allocation for it the same queue.

- **Derived from the highest suffix, not from a count.** With a count, a missing
  `-2` would hand out `-3` twice and turn a routine confirmation into a 500.

- **An unmatched receipt keeps its `UP-` reference when it is matched.** The
  number staff wrote down when the money appeared must still find it afterwards.
  The suffix parser skips references that are not in the booking's series, so a
  matched-in receipt neither looks like a suffix nor shifts the ones after it.

- **The `UP-` series shares `booking_reference_counters`.** That table has always
  been generic — a prefix and a next value. Renaming it would be cosmetic churn
  on live data; the name stays and both the service and the config say why.

### Changed

- `BookingReferenceGenerator` now delegates its locking to `ReferenceSequence`
  and keeps only the formatting. Behaviour is unchanged and its existing test is
  untouched. Two copies of the most concurrency-sensitive code in the project is
  how one of them quietly stops matching the other.

---

## Phase 3 — Payment records and states · 2026-08-05

The tables money will be recorded in, and the states it moves through. Still no
service that writes to them.

### Added

**Schema**
- `payments` — one receipt. `booking_id` is **nullable**, because the guideline
  §5 warns that mobile money statements lag and that till payments often do not
  carry the reference the customer was given. Money therefore arrives that
  nobody can attribute yet, and it has to be recordable the moment it is seen —
  otherwise it gets written on paper and is not in the system at all. A null
  booking is the unmatched payments queue.
- `payment_confirmations` — one row per payment, with a **unique key on
  `payment_id`**.
- `bookings.payment_status` — the §7.1 position, separate from `status` (§7.2).

**Domain**
- `PaymentStatus` — the lifecycle of one receipt.
- `BookingPaymentStatus` — spec §7.1 verbatim, as a property of the booking.
- `Payment`, `PaymentConfirmation`, with `Payment::hasShortfall()` and the
  `counted`, `unmatched` and `outstanding` scopes.

**Tests** — 234 passing, up from 209.

### Decisions

- **Spec §7.1's payment states are split in two.** As written they mix levels:
  `proof_submitted` describes a receipt, `partially_paid` describes a booking.
  An individual K500 cash payment is confirmed or it is not; it is never
  partially paid. Merged, confirming a balance payment would have to reach back
  and rewrite the earlier deposit row from `partially_paid` to `paid_in_full`,
  making a row's status depend on rows it knows nothing about and adding a
  second writer to settled history. Spec §7 opens by saying booking states and
  payment states must not be merged; this applies the same principle one level
  down. `BookingPaymentStatus` carries §7.1 verbatim so nothing is lost.

- **Confirmation is a row in its own table, not two columns on `payments`.**
  Spec §12 requires duplicate confirmation to be *structurally* impossible. With
  `confirmed_at` on the payment, confirming twice is an UPDATE, and no index
  refuses a second UPDATE — the best available guard is read-then-write, which
  is exactly what loses a race when two staff click confirm in the same instant.
  As an INSERT against a unique key, the database refuses the second writer
  regardless of what any caller checks. The service still locks and checks first,
  so the ordinary case reads "already confirmed by Mary at 14:32" instead of a
  SQL error — but that is courtesy, and the index is the guarantee.

- **`expected_amount` is stored per receipt, and is nullable.** A shortfall is
  measured against what was asked for at the time. The booking's `balance_due`
  cannot serve, because it moves as other payments are confirmed, so the same
  short payment would look different depending on when the question was asked.
  An unmatched receipt has no expectation, hence nullable — and it is therefore
  never a shortfall, only money nobody has attributed.

- **`RefundPending` counts towards `amount_paid`; `Refunded` does not.** A
  refund approved but not yet disbursed means the operator is still holding the
  cash. Counting it as gone would show the customer a balance they do not owe,
  and could let the expiry sweep cancel their booking for non-payment while
  their money sits in the till. Refunds are Phase 4, but the enum has to answer
  this now.

- **`payments` carries `operator_id`**, nullable, like every other core table.
  ARCHITECTURE §8: the seam exists so that opening the platform to other
  operators is not a migration across tables holding live data, and a table
  holding money is the worst one to migrate late.

### Fixed

- `BookingCreationService` sets `payment_status` explicitly rather than leaving
  it to the column default. `create()` does not read defaults back, so the
  returned model would have carried no value at all — null in production, a
  `MissingAttributeException` under strict mode. The same fault as the
  `CustomerResolver` `needs_staff_review` bug in Phase 2. The test reads it off
  the returned instance rather than re-querying, because a re-query would pass
  either way.

### Known gaps

- Nothing writes to these tables yet. `PaymentRecordingService` and
  `PaymentConfirmationService` are the next slices.
- No `refunds` table. The refund *states* exist; the workflow, and the
  two-person request-and-approve rule of spec §9.3, belong with Phase 4.

---

## Phase 3 — Roles and permissions · 2026-08-05

The first slice of the payments phase. Permissions come before payment records
because confirming a payment is permissioned by role *and* by method, so the
roles have to exist before the confirmation service can be written against them.

Nothing here takes money yet.

### Added

**Schema**
- The five `spatie/laravel-permission` tables. The migration is adapted from the
  package's stub rather than published: the timestamp has to sort after `users`,
  and the teams branches are dead code here.
- `users.operator_id` and `users.branch_id`, both nullable and both restricted
  on delete. Null is meaningful in each case — a Super Admin belongs to no
  branch — and a user whose `operator_id` silently became null would change
  from an operator's employee to platform staff, which is why the delete is
  refused rather than nulled.

**Domain**
- `StaffPermission` — all fifteen permissions of spec §12, values copied
  verbatim so the enum can be read against the spec line for line. Carries
  `toConfirm()`, mapping a payment method to the permission needed to confirm
  it.
- `StaffRole` — Counter Clerk, Branch Manager, Super Admin, with the §12 matrix
  as grants.
- `User` — roles, branch and operator. Customers are not users and never will
  be; spec §1.4 makes guest checkout the default and most customers never have
  a password.
- `RolesAndPermissionsSeeder` — idempotent, and authoritative for those three
  roles.

**Config**
- `config/permission.php`, written into the project rather than published, so
  the choices are reviewable. Teams off (the multi-operator seam is
  `operator_id`, not this package's feature). Wildcards off — §12 distinguishes
  confirming cash from confirming a transfer precisely because a clerk may do
  one and not the other, and `payments.*` is how that distinction gets lost.
  Permission and role names are kept out of exception messages.

**Tests** — 209 passing, up from 187.

### A bug the WithoutModelEvents test caught

The regression test written for the trap failed on first run, which is the
whole reason it exists.

`PermissionRegistrar` holds its permission collection in memory and reloads it
only when told to — normally by model events on `Permission` and `Role`.
`DatabaseSeeder` runs with `WithoutModelEvents`, which suppresses exactly those
events. So the first `Permission::findOrCreate()` loaded the collection while
the table was still empty, and because an empty collection is still a truthy
object the registrar never reloaded it. All fifteen permissions were created
against that stale view, and the first `syncPermissions()` then threw
`PermissionDoesNotExist` for a permission sitting in the table.

On a freshly deployed server this would have looked like staff being unable to
confirm payments, with the permissions plainly visible in the database.

Fixed by removing the seeder's dependence on that cache altogether: every read
is a direct query, and grants are passed to `syncPermissions()` as model
instances, which `collectPermissions()` returns untouched instead of resolving
by name. Flushing the cache between the two loops would also have worked, but
only until someone added a third loop.

### Decisions

- **The seeder is authoritative for the three seeded roles.** Grants are synced,
  not added, so re-running it restores the §12 matrix exactly and revokes
  anything granted to those roles by hand. An operator who needs a different
  combination gets a new role, not an edited Counter Clerk. The alternative —
  additive seeding — lets the live permission set drift away from the reviewed
  matrix with nothing noticing. There is a test for the revocation.
- **`payments.edit-manual-payment` and `bookings.override-short-notice` sit at
  Branch Manager and above.** Both are in the §12 permission list but neither
  has a row in the §12 matrix, so the specification does not say who holds them.
  Each is a correction to or an exception from the automatic path, the same
  shape as extending a deadline, which the matrix does place at Branch Manager.
  Recorded in `StaffRole::permissions()`; a seeder change moves either.
- **Counter clerks hold `payments.confirm-cash`, gated at the service.** §12
  marks it "Configurable per branch", so the grant is necessary but not
  sufficient — `PaymentConfirmationService` will also consult the
  `counter_clerk_may_confirm_cash` setting. Branch Managers and above are not
  subject to that gate. The per-branch override itself is deferred to the roles
  UI; see OPEN-ITEMS.md item 12.

### Known gaps

- Nothing enforces these permissions yet. `PaymentConfirmationService` and
  `PaymentPolicy` arrive with the payment records they guard.
- The per-branch cash confirmation override is still a single global setting.

---

## Phase 2 — Booking engine · 2026-08-04

Basket, quote, customers, payment methods, deadlines, the state machine and
booking creation. Still no user interface.

Scope grew by agreement: `payment_methods` and the deadline calculator were
pulled forward from Phase 3, because a booking cannot honestly be created
without a payment method and a deadline — checkout selects the method before it
submits. Phase 3 is now what it should be: payment records, references, proof
upload, staff confirmation and the expiry job.

### Added

**Schema**
- `customers` — with E.164 phone storage. Deliberately **no unique constraint**
  on email or phone; spec §1.4 requires a new unlinked record when details
  match, so a constraint would forbid the specified behaviour.
- `payment_methods` — the six methods of spec §3, four enabled.
- `bookings` — the quote as agreed, both deposits kept apart, and a snapshot of
  the vehicle at the moment of booking.
- `booking_reference_counters` — the locked source of `BR-00001`.
- Foreign key from `vehicle_holds.booking_id`, left open by Phase 1 until
  `bookings` existed.

**Domain**
- `BookingStatus`, `TransitionActor`, `BookingStateMachine` — spec §7.3 as a
  single readable map; undefined transitions throw.
- `PhoneNormaliser` — E.164 via libphonenumber, so international customers are
  not mangled into a duplicate on every visit.
- `CustomerResolver` — the §1.4 linking rules: a match never links silently.
- `PaymentMethodService` — three independent gates, all of which must pass.
- `PaymentDeadlineCalculator` — `min(hold duration, pickup − 2h)` and the
  under-four-hours rule.
- `Money` — bcmath at scale 2, rounding half up rather than truncating.
- `QuoteService` — one price, used by both search and checkout.
- `BookingReferenceGenerator`, `BookingCreationService`, `BasketService`.

**Tests** — 187 passing, including two multi-process concurrency suites.

### Two bugs found by the concurrency tests

Both were invisible to the single-process suite, which stayed green throughout.

**Stale reads under `REPEATABLE READ`.** The overlap check inside the vehicle
lock was a plain `SELECT`, so it read a snapshot fixed before the winning
transaction committed. Four of five racing processes inserted a duplicate hold
and were stopped only by the unique index — which catches identical ranges and
would not have caught merely overlapping ones. Fixed by moving the connection
to READ COMMITTED and making the guard a locking read.

**Gap-lock deadlocks.** The locking read then deadlocked transactions working on
*different* vehicles, because next-key locks on a nearly-empty index span
everything. Also resolved by READ COMMITTED.

### Fixed

- `CustomerResolver` returned a model whose `needs_staff_review` was `null`
  rather than `false`, because `create()` does not know the database's defaults.
  `null` is falsy, so the fault would have survived every boolean check.
- Two concurrency classes now reset `RefreshDatabaseState::$migrated` on
  teardown. `DatabaseTruncation` commits data and cleans up only *before* its
  own tests, so every later `RefreshDatabase` class inherited the mess. It had
  been latent for weeks because the only truncating class sorted last
  alphabetically.

### Decisions

- Basket lives in the session, not the database. No abandoned rows to sweep.
- The frozen quote is stored as scalars, not objects: a deploy that changes a
  DTO would otherwise break every basket mid-checkout.
- Booking references are gapless, at the cost of serialising creation on one
  counter row. Staff read these aloud to match bank payments; holes invite
  "what happened to BR-00042?".
- Short-notice bookings place **no hold**, per spec §8.2. The customer has a
  booking but not a guaranteed vehicle, and the confirmation must say so — a
  hard requirement for Phase 5.

### Known gaps

- KYC verification is not enforced in the vehicle-release guard; there is
  nothing to read it from until the admin panel exists. The check is written and
  commented in place, awaiting data.
- Extras and cross-border are carried as totals; neither has a table yet.

---

## Phase 1 — Foundations · 2026-08-03

Fleet, pricing, availability and the hold mechanism. No user interface — the
guideline is explicit that the booking engine is where the risk lives and that
the customer UI must not come first.

### Added

**Schema**
- `operators` — fleet owners. One at MVP; present from the start so opening the
  platform to other operators later is not a migration across every core table.
- `branches` — collection and return locations, with opening hours nullable
  until that policy is settled.
- `vehicle_classes` — carries daily rate, mandatory damage waiver, excess,
  refundable security deposit and turnaround buffer.
- `vehicles` — individual units, with nullable rate and deposit *overrides* of
  the class values.
- `vehicle_holds` — exclusive claims on a vehicle for a date range.
- `settings` — operator-editable configuration, with a placeholder flag.
- `audit_log` — append-only, enforced by MySQL `BEFORE UPDATE` and
  `BEFORE DELETE` triggers.

**Domain**
- `DateRange` — half-open hire window with chargeable-day and padding logic.
- `SettingsRepository` — typed, cached configuration access.
- `PricingService` — resolves the class → vehicle override chain; all money is
  bcmath strings at scale 2.
- `AvailabilityService` — which vehicles are free, honouring the turnaround
  buffer.
- `VehicleHoldService` — the single sanctioned writer of holds, and the only
  place double-booking is prevented.
- `VehicleStatus`, `InsurancePriceMode`, `SettingKey` enums.
- `carhire:attempt-hold` console command — test harness for concurrency, refuses
  to run in production.

**Tests**
- Concurrency: six real processes released at a shared barrier compete for one
  vehicle; exactly one wins.
- The inverse: simultaneous attempts on non-overlapping windows both succeed.
- Audit immutability proven at the database level via raw SQL, bypassing the
  model guard entirely.
- Turnaround buffer boundaries, released and lapsed holds, vehicle status,
  pricing overrides, per-day versus flat insurance, and a 23:30 Lusaka booking
  storing the correct UTC instant.

### Decisions

- Double-booking is prevented by `lockForUpdate()` inside a transaction rather
  than by a PostgreSQL exclusion constraint, because production is 20i shared
  hosting where PostgreSQL is not available. This makes the guarantee
  behavioural rather than structural — see ARCHITECTURE.md.
- The test suite runs on MySQL only. A concurrency test on SQLite would prove
  nothing, since SQLite has no row-level locking.
- Money is `DECIMAL(12,2)` handled as bcmath strings, never float.
- `audit_log` was built in Phase 1 rather than Phase 4 as the guideline
  suggests, because adding append-only triggers to a table that already holds
  rows is worse than creating it correctly the first time.

### Known gaps

- `vehicle_holds.booking_id` has no foreign key yet; `bookings` does not exist
  until Phase 2, which will add the constraint.
- The `TRIGGER` privilege on the production database is unverified. See
  OPEN-ITEMS.md.
