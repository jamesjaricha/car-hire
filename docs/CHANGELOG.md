# Changelog

Newest first. Each entry covers one phase of the build order set out in the
developer guideline §3.

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
