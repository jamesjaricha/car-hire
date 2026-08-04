# Developer Guide

Read this before writing code.

---

## Local setup

Developed on Windows with Laragon. PHP 8.4, MySQL 8.4.

```powershell
cd C:\laragon\www\carhire
composer install
php artisan key:generate
php artisan migrate
php artisan db:seed
```

Two databases are required. Laragon does not put `mysql` on the PATH, so use
the full path:

```powershell
& "C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe" -u root -e "CREATE DATABASE IF NOT EXISTS carhire CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE DATABASE IF NOT EXISTS carhire_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

The site is served by Laragon at `http://carhire.test` after a tray-icon reload.

---

## Running the tests

```powershell
cd C:\laragon\www\carhire
php artisan test
```

**The suite runs against real MySQL — `carhire_test` — not SQLite.** This is not
a preference:

- The concurrency test depends on row-level locking. SQLite has none, so a green
  SQLite run for that test would be meaningless.
- The `audit_log` immutability test depends on MySQL triggers.
- SQLite and MySQL disagree in ways that have burned previous projects — date
  ranges on `whereBetween`, ordering in aggregate queries. Green on SQLite has
  never meant safe on MySQL.

It is slower. That is the cost of the results being real.

### The concurrency test

`VehicleHoldConcurrencyTest` spawns six actual PHP processes via the `Process`
facade, each running `carhire:attempt-hold`. They boot, wait at a shared
timestamp barrier, and all attempt the same hold at the same instant.

The barrier matters. Without it each child spends a few hundred milliseconds
booting Laravel, so the first could finish before the last had even connected,
and the test would pass having proved nothing.

It uses `DatabaseTruncation` rather than `RefreshDatabase`, because
`RefreshDatabase` wraps each test in a transaction that is never committed — the
child processes would not be able to see the vehicle the test creates.

If it fails intermittently, raise `BARRIER_LEAD_SECONDS` before assuming the
lock is broken; a slow machine may need longer to get six processes booted.

### If you add another multi-process test

Two things bit us, both worth knowing before you write the third one.

**Clear the migration flag on teardown.**

```php
protected function tearDown(): void
{
    parent::tearDown();

    RefreshDatabaseState::$migrated = false;
}
```

`DatabaseTruncation` and `RefreshDatabase` share that one static. Whichever runs
first sets it, after which `RefreshDatabase` skips `migrate:fresh` and merely
opens a transaction — assuming a clean database. But truncation cleans up
*before* each of its own tests, never after, and child processes commit real
rows. Without this, every later test class inherits the leftovers. It cost 25
mystifying failures across five unrelated classes, none of which had a bug.

**Race the real call path, not the component.** The stale-read bug lived in
`VehicleHoldService`, but the hold test never caught it — calling `place()`
directly makes its lock the transaction's first read, so the snapshot is fresh.
The bug only existed through `BookingCreationService`, which reads four other
things first. Concurrency tests belong at the outermost transaction boundary.

**Test the case your safety net cannot catch.** The unique index masked the same
bug for identical date ranges. The test that mattered used *overlapping* ranges,
where only correct locking helps.

---

## Code style

Run Pint after every PHP change:

```powershell
cd C:\laragon\www\carhire
./vendor/bin/pint
```

Conventions:

- `declare(strict_types=1)` at the top of every PHP file.
- Classes `final` unless there is a reason to allow extension.
- Type hints on every parameter and return.
- Constructor property promotion, `readonly` where it fits.
- **British English** in comments and documentation.
- Comments explain *why*, not *what*. The code already says what.

---

## Architectural rules

**Never write to `vehicle_holds` outside `VehicleHoldService::place()`.** That
method holds the row lock that makes double-booking impossible. Any other insert
path silently reintroduces the race. See ARCHITECTURE.md §1.

**Never read `vehicles.daily_rate` or `vehicles.security_deposit_amount`
directly.** They are nullable *overrides*; null means "inherit from the class",
which is the normal case. Code that reads them raw prices most of the fleet at
zero. Go through `PricingService`.

**Never treat an availability result as a reservation.** It is advisory. Only
`place()` decides.

**Never put business logic in a controller or a model.** Services, bound to
contracts in `AppServiceProvider`.

**Never use float for money.** `DECIMAL(12,2)` and bcmath strings at scale 2,
normalised with `bcadd($value, '0', 2)` before comparison or arithmetic.

**Never hardcode a business value.** It goes in `settings`, seeded through
`SettingKey`. If it is not yet decided, seed it as a placeholder and add it to
OPEN-ITEMS.md.

---

## Eloquent strict mode

`Model::shouldBeStrict()` is on outside production. It will throw on lazy
loading, on silently discarding attributes that are not fillable, and on
accessing attributes that were never selected.

If a change trips it, that is usually a genuine N+1 or a genuine bug — fix the
cause rather than relaxing the setting. Eager-load with `->with()`.

---

## Adding to the schema

Migrations are additive and reversible. Once this is live, treat the production
database as sacred: no destructive operations, take a backup before any schema
change.

`vehicle_holds.booking_id` intentionally has no foreign key yet — the `bookings`
table does not exist until Phase 2, and that migration adds the constraint.

### Seeders

`DatabaseSeeder` uses `WithoutModelEvents`, which suppresses model hooks for the
whole run. This has previously left slugs and other derived columns null on a
fresh migrate-and-seed. It is safe here **only because every seeder and factory
sets each column explicitly** rather than relying on a boot hook. Keep it that
way, and if you add a model with a derived column, set it explicitly too.

---

## Local environment gotchas

These are machine-specific and have each cost real time before.

**Composer fails writing `vendor/composer/tmp-*.zip` — "Permission denied".**
Kaspersky File Anti-Virus locks each dist archive on access, with no alert.
Composer then falls back to slow git clones. Fix: pause protection briefly and
use dist, or `composer install --prefer-source`. A read-only GitHub token
avoids the anonymous rate limiting that follows.

**Extensions disappear after switching PHP version in Laragon.** Laragon
regenerates `php.ini` and leaves extensions commented out — `zip` in particular,
which breaks Composer. Re-enable after every version switch. `bcmath` is
compiled into the Windows builds and needs no entry.

**`mysql` is not on the PATH.** Use the full path shown above, or add
Laragon's bin folder to the PATH in its preferences.

---

## Where to look

| Question | File |
|---|---|
| How does the booking engine avoid double-booking? | `app/Services/Holds/VehicleHoldService.php` |
| What does a hire cost? | `app/Services/Pricing/PricingService.php` |
| What is free on these dates? | `app/Services/Availability/AvailabilityService.php` |
| What is configurable? | `app/Enums/SettingKey.php` |
| What is still undecided? | `docs/OPEN-ITEMS.md` |
| Why is it built this way? | `docs/ARCHITECTURE.md` |

---

## Before declaring anything done

1. Tests written **and run green** — `php artisan test`. Pint passing and
   migrations running are not tests; they prove style and schema, nothing about
   behaviour.
2. Pint run.
3. `docs/CHANGELOG.md` and `docs/TREEVIEW.md` updated in the same commit as the
   work.
4. Any new placeholder added to `docs/OPEN-ITEMS.md`.
