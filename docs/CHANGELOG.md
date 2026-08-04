# Changelog

Newest first. Each entry covers one phase of the build order set out in the
developer guideline §3.

---

## Phase 3 (in progress) — Roles and permissions · 2026-08-05

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
