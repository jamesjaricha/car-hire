# Open Items

Values the business must decide before go-live. Spec §15 calls these
"must be answered before build starts"; in practice most of them are figures
rather than architecture, so the platform treats every one as configuration
seeded with a placeholder. Nothing here blocks development, but **none of it may
still be a placeholder when the platform takes real money.**

Anything marked PLACEHOLDER is flagged in the `settings` table
(`is_placeholder = true`) and will be listed in the admin panel, so this file
and the running system cannot drift apart.

Last reviewed: 2026-08-09 (Phase 4, settings and fleet pricing).

---

## Blocking before launch

| # | Item | Where it lives | Status |
|---|---|---|---|
| 1 | Flat admin fee (ZMW), deducted from refunds | `settings.admin_fee_amount` | **PLACEHOLDER** `0.00` — ⚠ applied to real money; **answerable in the panel** since 2026-08-09 |
| 2 | Security deposit per vehicle class (ZMW) | `vehicle_classes.security_deposit_amount` | **NULL = undecided** per class; an undecided class is withheld from sale |
| 3 | Insurance price per class, and per-day vs flat | `vehicle_classes.insurance_price` / `insurance_price_mode` | **NULL = undecided** per class |
| 4 | Insurance excess the customer remains liable for | `vehicle_classes.insurance_excess_amount` | **NULL = undecided** per class |
| 5 | Accepted KYC documents, minimum driver age, minimum licence years, foreign licence policy | `settings.minimum_driver_age`, `minimum_licence_years`, `foreign_licence_accepted` | **PLACEHOLDER** |
| 6 | Cross-border: supported countries, price and document checklist per country | Not yet modelled — due in the cross-border phase | Outstanding |
| 7 | SMS provider and registered sender ID | `settings.sms_provider`, `sms_sender_id` | **PLACEHOLDER**, empty |
| 8 | Branch list, operating hours, after-hours pickup policy | `branches` table | **PLACEHOLDER** — two demo branches seeded locally |
| 9 | Fuel policy (full-to-full? charged shortfall rate?) | `settings.fuel_policy` | **PLACEHOLDER** `full_to_full` |
| 10 | Mileage policy (unlimited? daily cap and excess rate?) | `settings.mileage_policy` | **PLACEHOLDER** `unlimited` |
| 11 | Late return charge rate | `settings.late_return_hourly_charge` | **PLACEHOLDER** `0.00` |
| 12 | Whether counter clerks may confirm cash, per branch | `settings.counter_clerk_may_confirm_cash` | **PLACEHOLDER** `false`, global. The role grant exists; the per-branch override is due with the roles UI — see below |

### ⚠ Item 1 is now live, and it is the most urgent of the twelve

Until this slice, `admin_fee_amount` was a placeholder nothing read. Refunds read
it. **Every refund raised today deducts a fee of zero**, which means every
cancelling customer is given back more than the business intends to give them.

Refusing to raise refunds while the fee is undecided was considered and rejected
with the operator: it would make the whole feature unusable until a §15 answer
arrives, including the cross-border case where §11 says the fee is legitimately
zero. So the platform warns instead, in four places — the cancel-and-refund
modal, the refunds table, the refund record, and the approval modal — and freezes
`refunds.admin_fee_was_placeholder` onto the row so that a refund raised today
still reads as "computed with an undecided fee" after a real figure is entered.

**Resolved 2026-08-09:** the settings screen exists. `/admin` → Settings, Super
Admin only. Editing the fee records it as a decision and the warnings stop.

### Items 2, 3 and 4 changed shape on 2026-08-09

They used to be `NOT NULL DEFAULT 0`, which meant an unanswered figure and a
deliberate zero were the same value — and unlike item 1, these are shown to
customers. A class left at the default published "no deposit required" in search
results, at checkout and in the confirmation email, and spec §6 says the deposit
must never first appear at the counter.

They are now nullable. **Null means undecided, `0.00` means decided and zero.**
An incomplete class is refused by `PricingService` and withheld from search by
`AvailabilityService`, so it cannot reach a customer at all. The Vehicle classes
screen carries a badge counting them.

⚠ **The migration could not repair history.** Rows already at the old zero
default now read as deliberate zeros, and nothing can tell them apart. Review
every class in the panel before go-live rather than trusting the badge to be
complete.

## Settled

| Item | Decision | Source |
|---|---|---|
| Booking deposit percentage | 50% | Spec §5 |
| Short-notice threshold | 4 hours | Spec §8.2 |
| Deadline margin before pickup | 2 hours | Spec §8.2 |
| Basket lifetime | 30 minutes | Spec §1.1 |
| Payment reminder trigger | 25% of hold window remaining | Spec §8.4 |
| Cancellation notice window | 24 hours before pickup; inside it the booking deposit is forfeit | Spec §9.1, seeded as `cancellation_notice_hours` |
| Turnaround buffer between hires | 2 hours, per class | Confirmed with the operator, 2026-08-03 |
| One-way hires | Allowed by staff arrangement only; no automatic vehicle relocation | Confirmed with the operator, 2026-08-03 |
| Pricing level | Class sets rate and deposit; individual vehicles may override | Confirmed with the operator, 2026-08-03 |
| Currency | ZMW only | Spec header |

---

## Technical risks carried forward

**⚠ `TRIGGER` privilege — CONFIRMED ABSENT IN PRODUCTION, 2026-08-14. BLOCKING
REAL LAUNCH.**

No longer a risk; a live condition. `SHOW GRANTS` for `james-8c41` on the 20i
package `pule.jarichatech.com` returns no `TRIGGER` privilege, so the two
`BEFORE UPDATE` / `BEFORE DELETE` triggers protecting `audit_log` **do not exist
on the production database.**

What that means concretely: `audit_log` immutability is enforced only by
`AuditLogEntry`, which refuses updates and deletes in PHP. That protects every
path through the model — which is every path the application itself uses. It does
**not** protect against raw SQL, a database client, or a future model added by
somebody who has not read the docblock. Spec §12 requires the stronger form.

The migration no longer fails on this. It warns on stderr and in the log, on
every deploy, and continues — see the migration's own docblock for why failing
hard was worse. **This is a deliberate, disclosed downgrade for a demonstration
deployment, not an accepted permanent state.**

**It is reversible, and there is a mechanism:**

```bash
php artisan carhire:install-audit-triggers --check   # reports, exit 1 if unprotected
php artisan carhire:install-audit-triggers           # installs whatever is missing
```

Ask the host for `GRANT TRIGGER ON \`<database>\`.* TO \`<user>\`@\`%\`;` then run
the command. No data is touched and no migration is needed. There is a test that
drops the triggers, restores them with the command, and proves a raw `UPDATE` is
refused afterwards.

**Before this platform takes real money**, either the privilege is granted and
the command run, or the operator accepts in writing that the audit trail is
application-enforced. The operator was told about this alongside the demo link.

**Prior wording, kept for context:** "Shared hosting does not always grant the
`TRIGGER` privilege. Verify on the production database before launch, not
after." That was the correct instruction and following it is what found this —
before any real booking existed, which was the point.

**Hold expiry depends on the scheduler.** A dead expiry job would normally lock
vehicles out of sale indefinitely. Mitigated two ways: the availability query
ignores holds whose deadline has passed, and placing a hold retires that
vehicle's lapsed holds first. A manual "release expired holds" admin action is
still required, per the developer guideline.

**Double-booking prevention is behavioural, not structural.** On PostgreSQL this
would be an exclusion constraint the database enforces regardless of application
code. On MySQL it is a row lock taken inside `VehicleHoldService::place()`, which
is correct only while that remains the sole writer to `vehicle_holds`. The
concurrency test exists to keep it that way.

**The database must run at READ COMMITTED.** Set in `config/database.php` and
overridable by `DB_ISOLATION_LEVEL`. The booking engine is incorrect under
InnoDB's `REPEATABLE READ` default — see ARCHITECTURE.md §1. Verify on the
production connection after deploying; a managed host that pins the isolation
level would reintroduce both failures silently.

---

## Requirements this build has created for later phases

Recorded here so they are not rediscovered as bugs.

**Short-notice bookings must say the vehicle is not guaranteed.** Spec §8.2
places no hold when pickup is under four hours away — availability is
first-come at the counter. The customer has a booking, not a car. The
confirmation screen, email and SMS must all say so plainly, or someone drives to
a branch expecting a vehicle that has gone. Due with the customer UI.

**Checkout must never reveal whether an account exists.** `CustomerResolution
Result::$anExistingRecordMatched` is server-side only. If the checkout renders
a different screen, message or set of buttons depending on it, an attacker can
enumerate which email addresses have accounts simply by starting checkouts. The
sign-in and continue-as-guest options must be offered identically to everyone.
Spec §1.4. Due with the customer UI.

**Cash confirmation is gated globally, not per branch.** Spec §12 marks a
counter clerk's cash confirmation as "Configurable per branch" and §15.12 makes
the policy itself an open item. Today the clerk holds
`payments.confirm-cash` and `PaymentConfirmationService` additionally consults
one global setting, which defaults to false. That is the whole of the rule: a
branch cannot yet differ from its neighbour. Implementing the override means a
nullable `branches.counter_clerk_may_confirm_cash` inheriting the global default
when null — deliberately not built yet, because the business has not decided
whether it wants the distinction at all. Due with the roles UI.

**Five §12 permission decisions — three SETTLED 2026-08-05, two 2026-08-08.**
Kept here because they are departures from the specification and a future reader
should find the reasoning rather than rediscover the discrepancy. The last two
are recorded immediately above.

1. `payments.edit-manual-payment` — §12 lists it without saying who holds it.
   **Branch Manager and above.** It changes a figure somebody has already relied
   on.
2. `bookings.override-short-notice` — likewise unplaced by §12. **Counter Clerk
   and above.** The clerk is the one facing a customer three hours before
   pickup; making them fetch a manager is the friction the override exists to
   remove.
3. `payments.record-manual` — **not in §12 at all, added deliberately.** §12 has
   no permission covering the act of writing down money that arrived. Guarding
   it with `payments.edit-manual-payment` would have forced a choice between
   letting clerks alter recorded payments or stopping the people at the till
   from recording money as it arrives. Splitting it keeps "record what arrived"
   and "change what was already recorded" as separate powers. **Counter Clerk
   and above.**

**Panel data is not scoped to a user's branch.** Decided 2026-08-05: everyone
who can see a screen sees every branch's records. Spec §12 scopes *actions* per
branch, never visibility, and there is one operator — so scoping now would mean
inventing a rule the business has not asked for. It is recorded here as a
decision rather than an omission.

Worth revisiting before launch, and cheaper to add than to explain: a Branch
Manager in Lusaka can currently see Livingstone's takings. If the operator wants
that limited, note that one-way hires span two branches, so "their branch" needs
defining before it can be built — pickup, drop-off, or either.

**Part-paid bookings past their deadline — RESOLVED 2026-08-08.**
`BookingResource` tab "Needs a decision", visible to anyone holding
`payments.extend-deadline`. All three ways to resolve one now exist: extend the
deadline, take the balance, or cancel and refund. The third was the gap that
mattered — until refunds landed, a booking whose customer wanted their money back
had no route through the panel at all.

**Superseded: part-paid bookings past their deadline have no screen.** The expiry sweep
deliberately refuses to cancel a booking holding the customer's money — spec
§8.4 assumes nothing was received, and spec §9.3 wants two people on any refund,
neither of whom is a cron job. `Booking::scopeStalledAfterDeadline()` is the
queue and `carhire:expire-bookings` prints its count on every run, but until the
admin panel has a screen for it, that log line is the only thing that will tell
anyone those bookings exist. Each one is holding money somebody paid. Due with
the admin panel, and the highest priority of the queues listed here.

**A confirmed hold is not released when the car comes back early — HALF CLOSED
2026-08-08.** Confirming a payment extends the hold's `expires_at` to the end of
the hire, which is what keeps the vehicle claimed for the whole booking
(ARCHITECTURE §3). Nothing shortened it again.

`BookingCancellationService` now releases every unreleased hold when a booking is
cancelled or marked a no-show, which closes the cancellation half.

**Still open: the `completed` transition.** A customer returning on Tuesday a car
booked until Friday leaves it claimed until Friday, and those days cannot be
resold. Not a correctness problem, a revenue one — and an invisible one, because
the vehicle simply stops appearing in searches. Due with the returns workflow.

**Payment method account details — RESOLVED 2026-08-09.** There is a screen:
`/admin` → Payment methods, Super Admin only. And the consequence is no longer
silent: a method switched on without the details its adapter requires is now
**withheld from checkout** rather than offered with blanks, so a customer can
never be told to send money nowhere. Cash requires nothing, so a fresh install
still takes bookings while the operator enters real numbers.

⚠ **The operator must still enter them before go-live.** Nothing is seeded in
production on purpose. Until they are entered, bank transfer and both mobile
money methods do not appear at checkout at all — which is safe, and will look
like a fault to anybody who does not know why. The navigation badge counts them.

**Superseded — payment method account details are empty.** `PaymentMethodSeeder` leaves
`account_details` null on every method, deliberately — real bank account and
merchant numbers are operator data and do not belong in source control. Until
they are entered, bank transfer and mobile money instructions render with blanks
where the account and till numbers should be, and
`PaymentAdapter::isConfigured()` reports false. The admin panel must surface
that; a customer being told to transfer money to nowhere is worse than the
method being switched off. Due with the payment methods screen in Phase 4.

**Two §12 permission gaps the refunds work exposed — SETTLED 2026-08-08.** Kept
here because both are departures from the specification and a future reader
should find the reasoning rather than rediscover the discrepancy.

4. `bookings.cancel` — **not in §12 at all, added deliberately.** §12 lists
   fifteen permissions and none covers ending a hire, which only became visible
   once the panel could do it. The first implementation asserted nothing and let
   the calling screen's `refunds.request` stand in, which meant the real answer
   to "who may cancel a booking" was "everybody" and the only place to find it
   was a service docblock. **Counter Clerk and above** — the clerk faces the
   customer who wants to cancel, and cancelling only starts the process, because
   the refund that follows still needs a manager to approve it.

5. `refunds.disburse` — **not in §12 at all, added deliberately.** §9.3
   separates requesting from approving and says nothing about who physically
   pays. Borrowing `refunds.approve` for it would have meant a clerk could hand
   back a security deposit — which §12 explicitly permits — but not a refund,
   across the same counter, to the same customer. **Counter Clerk and above.**
   They execute an approval somebody more senior gave, at an amount neither can
   edit, and their name goes on the disbursement reference.

⚠ **Both are new permission rows.** Any database seeded before 2026-08-08 needs
`RolesAndPermissionsSeeder` re-run, or `hasPermissionTo()` will throw
`PermissionDoesNotExist` rather than returning false — a missing permission row
is treated as a deployment fault, deliberately, because a silent denial gets
diagnosed as a role misconfiguration and sent to the wrong person.

**Eight §12 departures as of 2026-08-13.** `fleet.manage-vehicles` is the
newest, added when the panel first got a screen for individual vehicles. It sits
at **Branch Manager and above**, deliberately wider than `fleet.manage`, which
stays Super Admin.

The two are not the same power and the gap is the design. A vehicle class is a
price list applying to every branch that holds one; a vehicle is a car in a
yard, and the manager of the branch it sits at is the person who knows it has
gone in for repair. Routing that through Head Office is how a fleet list goes
stale.

The seam that needed care: `vehicles.daily_rate` and
`vehicles.security_deposit_amount` are nullable overrides, so "may edit a
vehicle" would have handed back the pricing power `fleet.manage` was withheld to
protect. `VehicleForm` disables both fields without `fleet.manage` and does not
dehydrate them, so a manager's save cannot change *or clear* an override. There
is a test asserting a manager's save leaves both figures untouched.

⚠ **This is a new permission row.** Any database seeded before 2026-08-13 needs
`RolesAndPermissionsSeeder` re-run, or `hasPermissionTo()` throws
`PermissionDoesNotExist` rather than returning false. Deliberate — a missing
permission row is a deployment fault, and a silent denial gets diagnosed as a
role misconfiguration and sent to the wrong person.

**The class page resolves by a slug that is only unique per operator.**
`/classes/{slug}` looks up `vehicle_classes.slug`, but the unique index is on
`(operator_id, slug)` — so two operators may both have `economy`, and the lookup
becomes ambiguous the moment a second one exists. It is correct today because
the platform serves one operator, and it is written down here rather than left
to be discovered.

Not worth fixing in isolation: opening the platform to other operators needs an
operator context on **every** public route — a domain, a subdomain or a path
segment — and this lookup is one of the things that resolves at that point.
Binding by id instead would avoid the ambiguity at the cost of a worse
customer-facing URL, and would still not answer which operator's site a visitor
is on.

**The vehicle-image fallback is written out three times.** `home.blade.php`,
`components/vehicle-card.blade.php` and `vehicle.blade.php` each contain their
own copy of "photograph if there is one, illustrated panel if not". They drifted
once already — the home page was rendering grey make-and-model text while the
other two drew the silhouette, and the home page was the one being demonstrated.
They were brought back into line on 2026-08-13 rather than unified, which fixes
the symptom and leaves the cause.

Extracting an `x-vehicle-image` component is the real fix. It is deferred rather
than forgotten because the per-vehicle photograph work will touch all three
call sites anyway — the fallback chain becomes vehicle, then class, then
silhouette — and that is the natural moment to do it once. A test asserts the
brand-tinted panel is present and the old grey one is not, so a fourth copy
appearing in the old style is caught.

**KYC verification is not yet enforced on vehicle release.** Spec §14.6 requires
KYC verified, balance settled and security deposit recorded. The latter two are
enforced now; the first has nowhere to be read from until the admin panel
exists. `TransitionContext::$kycVerified` is in place and the guard is written
and commented out awaiting data. Must be switched on with the KYC workflow.
