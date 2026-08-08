# Open Items

Values the business must decide before go-live. Spec §15 calls these
"must be answered before build starts"; in practice most of them are figures
rather than architecture, so the platform treats every one as configuration
seeded with a placeholder. Nothing here blocks development, but **none of it may
still be a placeholder when the platform takes real money.**

Anything marked PLACEHOLDER is flagged in the `settings` table
(`is_placeholder = true`) and will be listed in the admin panel, so this file
and the running system cannot drift apart.

Last reviewed: 2026-08-05 (end of Phase 3).

---

## Blocking before launch

| # | Item | Where it lives | Status |
|---|---|---|---|
| 1 | Flat admin fee (ZMW), deducted from refunds | `settings.admin_fee_amount` | **PLACEHOLDER** `0.00` |
| 2 | Security deposit per vehicle class (ZMW) | `vehicle_classes.security_deposit_amount` | **PLACEHOLDER** per class |
| 3 | Insurance price per class, and per-day vs flat | `vehicle_classes.insurance_price` / `insurance_price_mode` | **PLACEHOLDER** per class |
| 4 | Insurance excess the customer remains liable for | `vehicle_classes.insurance_excess_amount` | **PLACEHOLDER** per class |
| 5 | Accepted KYC documents, minimum driver age, minimum licence years, foreign licence policy | `settings.minimum_driver_age`, `minimum_licence_years`, `foreign_licence_accepted` | **PLACEHOLDER** |
| 6 | Cross-border: supported countries, price and document checklist per country | Not yet modelled — due in the cross-border phase | Outstanding |
| 7 | SMS provider and registered sender ID | `settings.sms_provider`, `sms_sender_id` | **PLACEHOLDER**, empty |
| 8 | Branch list, operating hours, after-hours pickup policy | `branches` table | **PLACEHOLDER** — two demo branches seeded locally |
| 9 | Fuel policy (full-to-full? charged shortfall rate?) | `settings.fuel_policy` | **PLACEHOLDER** `full_to_full` |
| 10 | Mileage policy (unlimited? daily cap and excess rate?) | `settings.mileage_policy` | **PLACEHOLDER** `unlimited` |
| 11 | Late return charge rate | `settings.late_return_hourly_charge` | **PLACEHOLDER** `0.00` |
| 12 | Whether counter clerks may confirm cash, per branch | `settings.counter_clerk_may_confirm_cash` | **PLACEHOLDER** `false`, global. The role grant exists; the per-branch override is due with the roles UI — see below |

## Settled

| Item | Decision | Source |
|---|---|---|
| Booking deposit percentage | 50% | Spec §5 |
| Short-notice threshold | 4 hours | Spec §8.2 |
| Deadline margin before pickup | 2 hours | Spec §8.2 |
| Basket lifetime | 30 minutes | Spec §1.1 |
| Payment reminder trigger | 25% of hold window remaining | Spec §8.4 |
| Turnaround buffer between hires | 2 hours, per class | Confirmed with the operator, 2026-08-03 |
| One-way hires | Allowed by staff arrangement only; no automatic vehicle relocation | Confirmed with the operator, 2026-08-03 |
| Pricing level | Class sets rate and deposit; individual vehicles may override | Confirmed with the operator, 2026-08-03 |
| Currency | ZMW only | Spec header |

---

## Technical risks carried forward

**`TRIGGER` privilege on production.** `audit_log` immutability is enforced by
MySQL `BEFORE UPDATE` and `BEFORE DELETE` triggers. These are confirmed working
locally on MySQL 8.4.3. Shared hosting does not always grant the `TRIGGER`
privilege. If production refuses to create them, the guarantee falls back to an
application-level check, which is weaker than spec §12 requires. **Verify on the
production database before launch, not after.**

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

**Three §12 permission decisions — SETTLED 2026-08-05.** Kept here because they
are departures from the specification and a future reader should find the
reasoning rather than rediscover the discrepancy.

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

**Part-paid bookings past their deadline now have a screen.** `BookingResource`
tab "Needs a decision", visible to anyone holding `payments.extend-deadline`.
Two of the three ways to resolve one exist: extend the deadline, or take the
balance. **The third — cancel with a refund — still does not**, because there is
no refunds table. Until it lands, a booking whose customer wants their money
back has no route through the panel at all.

**Superseded: part-paid bookings past their deadline have no screen.** The expiry sweep
deliberately refuses to cancel a booking holding the customer's money — spec
§8.4 assumes nothing was received, and spec §9.3 wants two people on any refund,
neither of whom is a cron job. `Booking::scopeStalledAfterDeadline()` is the
queue and `carhire:expire-bookings` prints its count on every run, but until the
admin panel has a screen for it, that log line is the only thing that will tell
anyone those bookings exist. Each one is holding money somebody paid. Due with
the admin panel, and the highest priority of the queues listed here.

**A confirmed hold is not released when the car comes back.** Confirming a
payment extends the hold's `expires_at` to the end of the hire, which is what
keeps the vehicle claimed for the whole booking (ARCHITECTURE §3). Nothing yet
shortens it when a hire ends early — a customer returning on Tuesday a car
booked until Friday leaves it claimed until Friday, and those days cannot be
resold. Not a correctness problem; a revenue one. `completed` and
`cancelled_*` transitions should release the hold. Due with the returns workflow
in Phase 4.

**Payment method account details are empty.** `PaymentMethodSeeder` leaves
`account_details` null on every method, deliberately — real bank account and
merchant numbers are operator data and do not belong in source control. Until
they are entered, bank transfer and mobile money instructions render with blanks
where the account and till numbers should be, and
`PaymentAdapter::isConfigured()` reports false. The admin panel must surface
that; a customer being told to transfer money to nowhere is worse than the
method being switched off. Due with the payment methods screen in Phase 4.

**KYC verification is not yet enforced on vehicle release.** Spec §14.6 requires
KYC verified, balance settled and security deposit recorded. The latter two are
enforced now; the first has nowhere to be read from until the admin panel
exists. `TransitionContext::$kycVerified` is in place and the guard is written
and commented out awaiting data. Must be switched on with the KYC workflow.
