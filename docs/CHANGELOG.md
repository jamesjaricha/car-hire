# Changelog

Newest first. Each entry covers one phase of the build order set out in the
developer guideline §3, or one slice of a phase still in progress.

---

## Phase 4 — Two permissions §12 never named · 2026-08-08

A follow-up to the refunds slice, which exposed both gaps by giving the panel a
way to reach them. Settled with the operator rather than left on a default.

### Added

- **`bookings.cancel`** — Counter Clerk and above. §12 lists fifteen permissions
  and none covers ending a hire. `BookingCancellationService` shipped asserting
  nothing, letting the calling screen's `refunds.request` stand in, so the real
  answer to "who may cancel a booking" was "everybody" and the only place to
  find it was a docblock. The reach is unchanged; the rule is now in the matrix,
  the seeder and the roles screen where it can be reviewed.
- **`refunds.disburse`** — Counter Clerk and above. §9.3 separates requesting
  from approving and says nothing about who physically pays. Disbursement had
  borrowed `refunds.approve`, which meant a clerk could hand back a security
  deposit under §12 but not a refund, across the same counter, to the same
  customer. Approval stays at Branch Manager: the counter executes a decision
  somebody else made, at an amount neither of them can edit.

`CancelAndRefundAction` now requires both `bookings.cancel` and
`refunds.request`, so the button is never offered to someone who could cancel a
booking but not raise the refund that must follow it — which would strand a
customer's money.

### ⚠ Deployment note

These are new permission rows. **Any database seeded before this must have
`RolesAndPermissionsSeeder` re-run**, or `hasPermissionTo()` will throw
`PermissionDoesNotExist` rather than returning false. That is deliberate — a
missing permission row is a deployment fault, and a silent denial gets diagnosed
as a role misconfiguration and sent to the wrong person to fix.

---

## Phase 4 — Refunds · 2026-08-08

Spec §9, end to end: cancel a booking, compute what is owed, have a second person
agree it, pay it out once. This closes the third and last way to resolve a
part-paid booking past its deadline — until now the panel could extend the
deadline or take the balance, but a customer who wanted their money back had no
route through the system at all.

### Added

- **`refunds`** and **`refund_disbursements`** tables. The second exists only for
  its `UNIQUE(refund_id)`; the first carries a `CHECK` constraint refusing an
  approver who is also the requester.
- `RefundReason` (§9.1, §9.2, §11) and `RefundStatus`.
- **`RefundCalculator`** — pure, and the piece with the exhaustive test matrix.
- **`BookingLedger`** — the single owner of `amount_paid`, now extracted from
  `PaymentConfirmationService` and shared with disbursement.
- **`BookingCancellationService`** — a person ending a booking, as the
  counterpart to `BookingExpiryService`'s clock. Releases the vehicle hold.
- **`RefundRequestService`** (request / approve / reject) and
  **`RefundDisbursementService`** (disburse), separate because §9.3 separates the
  people.
- Read-only `RefundResource` with two queue tabs, `RefundPolicy`, and four
  actions: approve, reject, record payout, and `CancelAndRefundAction` on the
  booking screens.
- `carhire:attempt-refund-disbursement` and `RefundDisbursementConcurrencyTest` —
  the fourth multi-process suite.
- `SettingKey::CancellationNoticeHours`, seeded at 24 (§9.1) as a real value
  rather than a placeholder, and `SettingsRepository::isPlaceholder()`.
- `AuditAction::RefundRejected`.

**Tests** — not yet run against this slice; see the note at the end of this entry.

### Decisions

- **Refunds are their own ledger; payment rows are never rewritten.**
  `amount_paid` is `SUM(confirmed receipts) − SUM(disbursed refunds)`. Flipping
  the original receipt to `refunded` would destroy the record of an event that
  genuinely happened — money did arrive, on a date, verified by a named person —
  and leave the month it fell in unreconcilable. `PaymentStatus::RefundPending`
  and `Refunded` therefore stay unused at MVP.

- **Double disbursement is prevented by a unique key, not by a check.** Same
  argument as `payment_confirmations`, and the stronger case of the two: a
  duplicated confirmation overstates what a customer paid and can be unpicked
  from the records, while a duplicated payout is cash that has left the building.
  Proved with four racing processes.

- **The two-person rule is a database constraint as well as a service guard.**
  §9.3 is a fraud control, not a workflow nicety, and one that lives only in
  application code is a single careless method away from being absent. There is a
  test that writes to the table directly to prove the constraint holds with no
  service involved.

- **The refund amount is computed and locked.** No caller can pass a figure and
  no form offers one. An editable amount makes §9 advice rather than policy, and
  the person best placed to abuse it is the one raising the request.

- **The figures are frozen onto the row at request time**, for the same reason
  `payments.expected_amount` is. The admin fee is a setting somebody can change
  this afternoon, `amount_paid` moves whenever a receipt is confirmed, and
  whether a cancellation was inside 24 hours stops being true a day later.

- **One action, two services, one transaction.** `CancelAndRefundAction` calls
  `BookingCancellationService` and `RefundRequestService`; neither learns the
  other exists. A cancelled booking with no refund raised against it is exactly
  the queue Phase 3 left behind, and the transaction is what stops the panel
  recreating it one failed request at a time.

- **Cancelling releases the vehicle hold.** `claimsVehicle()` is already false
  for every cancelled state, so leaving the hold would keep a cancelled booking's
  car off sale until the original hire ended — invisible, and unsellable. Closes
  half of the OPEN-ITEMS entry; the early-return half is still open.

- **A refund of zero is not recorded.** A late cancellation can forfeit exactly
  what was paid. Creating a row for it would ask two people to sign off a payment
  that will never be made, and it could never be disbursed — §9.3 wants a
  reference and there is none for money that did not move. The booking is still
  cancelled and the calculation survives in the audit entry.

- **The §15.1 placeholder warns rather than blocks.** Every screen that shows the
  admin fee says when it is undecided, and `admin_fee_was_placeholder` is frozen
  onto the refund so a row raised today still reads as such after the operator
  enters a real figure. Refusing outright was considered and rejected: it would
  have made the whole feature unusable until a §15 answer arrived.

- **Disbursement requires `refunds.approve`.** §12 names no permission for
  handing money over. Requiring the one it does name for authorising it is the
  safer default; the operator may well want counter clerks to hand back cash, and
  it is recorded in OPEN-ITEMS as their decision rather than assumed here.

- **`BookingCancellationService` asserts no permission of its own**, which is a
  departure from ARCHITECTURE §10. §12 defines no `bookings.cancel`, and
  inventing one silently would be a fourth undocumented departure. Authorisation
  sits with the caller — the panel action, gated on `refunds.request`. Recorded
  in OPEN-ITEMS as an outstanding §12 question.

### Also changed

- `PaymentConfirmationService` no longer computes the paid total itself. Its
  `paidTotalFor()` and `positionFor()` are gone, replaced by one
  `BookingLedger::positionFor()` call. Behaviour is unchanged where no refunds
  exist; two implementations of "how much has this booking been paid" would have
  agreed only until one of them was edited.
- `SettingsRepository`'s cache key is versioned (`carhire.settings.v2`) because
  the cached payload gained `is_placeholder`, and `rememberForever` would
  otherwise keep serving the old shape after deployment.

### Not yet verified

This entry was written with the code. **The suite has not been run against it**,
no migration has been applied, and Pint has not been run. Nothing here should be
treated as green until `php artisan test` says so.

---

## Phase 4 — The booking screens · 2026-08-05

The first resource in the panel, and the queue the whole slice was for.

### Added

- `BookingResource` — read-only, with tabs: All, Awaiting payment, **Needs a
  decision**, Confirmed, Collecting soon.
- `BookingPolicy` — `create`, `update` and `delete` all return false.
- `ExtendDeadlineAction` and `TakeBalanceAction`, both calling the services
  committed in `7e19de3`.
- `BookingInfolist` — one booking, with the two deposits deliberately in
  separate sections.

**Tests** — 392 passing, up from 379.

### Decisions

- **Read-only is enforced by the policy, not by convention.** Filament reads
  policies to decide which buttons to render, so returning false from `create`,
  `update` and `delete` both hides those controls and refuses them. That matters
  more than it looks: ARCHITECTURE §11 says bookings get no forms, and a rule
  kept only in a docblock is one `make:filament-resource` away from being broken
  by somebody in a hurry. There are tests asserting the pages are only `index`
  and `view`, and that `/admin/bookings/create` and `.../edit` 404.

- **The queue tab is gated on `payments.extend-deadline`, not on a role name.**
  You see the queue if you can act on it. Gating on a role would duplicate §12's
  matrix in the UI, and the two would drift.

- **Counter clerks still see the booking list.** The decision was about who sees
  the *queue*; locking clerks out of bookings entirely would stop them serving
  customers at the desk, which §12 plainly intends them to do. They hold
  `payments.view`, so they get the list and not the queue.

- **The amount at the counter is typed, not assumed.** The field defaults to the
  outstanding balance and stays editable, because customers hand over the wrong
  figure and the system must record what actually changed hands. The shortfall is
  reported back exactly as it would be for a transfer.

- **Domain exceptions become notifications; everything else bubbles.** A
  `DeadlineNotExtendableException` is a sentence for the person at the screen. A
  `QueryException` is a bug for us. Catching both and flattening them into the
  same red toast is how real faults get mistaken for user error, so the actions
  catch only the domain exceptions by name.

- **Actions are hidden where they cannot succeed** — no extend button on a
  confirmed booking, no take-payment button on a cancelled one or where nothing
  is owed. The services refuse these cases regardless; hiding the button stops
  staff learning a rule by being told no.

- **No branch scoping.** Everyone who can see a screen sees every branch's
  records. §12 scopes actions per branch, never visibility. Recorded in
  OPEN-ITEMS as a decision to revisit rather than an omission.

### Notes for whoever adds the next resource

Filament 5 moved things. Verified against the installed source rather than
remembered: all actions live in `Filament\Actions\*`, tables use
`->recordActions()` and `->toolbarActions()`, list-page tabs are
`Filament\Schemas\Components\Tabs\Tab` with `modifyQueryUsing()`, `Section` is in
`Filament\Schemas\Components`, and in tests a row action is targeted with
`TestAction::make('name')->table($record)` — `callAction()` takes no `record`
argument.

---

## Phase 4 — Deadline extension and counter payments · 2026-08-05

The two service capabilities the stalled-bookings queue needs. No UI yet — this
is the money-touching half, kept separate so it can be reviewed on its own.

### Added

- `PaymentDeadlineExtensionService` — spec §8.2 and §12
  `payments.extend-deadline`.
- `CounterPaymentService` — money handed over in person, recorded and confirmed
  in one transaction.
- `VehicleHoldService::extendToDeadline()`.
- `PaymentDeadlineCalculator::reminderFor()` promoted from private to the
  contract.

**Tests** — 379 passing, up from 354.

### Decisions

- **Extending a deadline is a service, not a column update.** A deadline lives
  in two places: `bookings.payment_deadline_at`, and the `expires_at` of the
  hold keeping the vehicle off sale until then. Moving only the first gives the
  customer another day and releases their car in the same breath — the same
  failure as a confirmed booking losing its vehicle, reached by a different
  route. Both move together, in one transaction. ARCHITECTURE §3 now lists all
  three things that may move a hold's expiry and the rule they share.

- **The reminder is recalculated rather than left alone.** A reminder still
  pointing inside the old window has already passed and will never fire, so the
  customer would get extra time and no nudge at all. `reminderFor()` became
  public so the extension path uses the same rule that set it originally instead
  of growing a second copy that drifts when the percentage setting changes.

- **The service polices coherence, not generosity.** Spec §8.2 lets staff
  "extend any deadline or approve an exception", so there is no cap on how far —
  a manager who has spoken to the customer knows things the automatic rule does
  not. What is refused: a deadline after the pickup it precedes (the customer
  would collect a car before they were due to pay for it), a booking that never
  had a deadline, one that has stopped waiting to be paid, and bringing a
  deadline *forward* — shortening a promise already made is a cancellation
  decision, and should not wear the name "extend".

- **Counter payments are one call because the counter case is genuinely
  different.** Online, a receipt is raised and confirmed hours apart by
  different people, and the gap between them is where `proof_submitted` lives.
  At a counter the staff member *is* the verification — they are holding the
  cash. Making them raise a receipt and then separately confirm money they just
  counted is theatre, and the kind that gets skipped.

- **It is one call, not a shortcut.** `CounterPaymentService` calls the same two
  services in the same order, so `payments.record-manual`, the method's own §12
  confirmation permission, the §15.12 cash setting, both row locks and the
  unique key on `payment_confirmations` all still apply. A counter clerk can
  take cash and still cannot sign off a bank transfer that happened to arrive
  while they were at the desk — there is a test for exactly that.

- **The whole thing is one transaction.** A refused confirmation must not leave
  a receipt behind: that would be money reading as unpaid which *cannot* be
  confirmed later, because its reference is already spent.

### Changed

- `raiseForBooking()` takes an optional expected amount and an optional
  recording staff member. A balance is neither the deposit nor the full total,
  so the derived figure is wrong for it; and a counter payment is not the
  customer's checkout, so recording it as though no person were involved would
  put money in the trail with nobody accountable for it.
- `is_deposit` is now false whenever an explicit expected amount is given. A
  balance receipt is not a deposit, and the previous derivation —
  `! $booking->pay_in_full` — would have called it one.

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
