# Treeview

Annotated map of the parts of the codebase this project actually wrote.
Framework scaffolding that has not been meaningfully changed is summarised
rather than listed.

Last updated: end of Phase 1, 2026-08-03.

```
carhire/
├── app/
│   ├── Console/
│   │   └── Commands/
│   │       └── AttemptVehicleHoldCommand.php   Test harness. Attempts one hold and
│   │                                           reports the outcome as an exit code.
│   │                                           Refuses to run in production.
│   │
│   ├── Contracts/                              Interfaces for the domain services.
│   │   ├── AvailabilityServiceContract.php     Which vehicles are free. ADVISORY ONLY —
│   │   │                                       read the note in the file.
│   │   ├── PricingServiceContract.php          What a vehicle costs.
│   │   ├── SettingsRepositoryContract.php      Typed configuration access.
│   │   └── VehicleHoldServiceContract.php      The only sanctioned way to claim a vehicle.
│   │
│   ├── DataTransferObjects/
│   │   └── DateRange.php                       A hire window, half-open [start, end).
│   │                                           Owns chargeable-day and buffer-padding logic.
│   │
│   ├── Enums/
│   │   ├── InsurancePriceMode.php              per_day | flat
│   │   ├── SettingKey.php                      Every operator-editable setting, with the
│   │   │                                       spec section each one answers.
│   │   └── VehicleStatus.php                   available | maintenance | retired
│   │
│   ├── Exceptions/
│   │   ├── AuditLogImmutableException.php      Reaching this is always a bug.
│   │   ├── InvalidDateRangeException.php       Return before collection, or zero-length hire.
│   │   └── VehicleNotAvailableException.php    The losing side of a race. Expected, not a fault.
│   │
│   ├── Models/
│   │   ├── AuditLogEntry.php                   Append-only. Refuses update and delete.
│   │   ├── Branch.php
│   │   ├── Operator.php                        Fleet owner. The multi-operator seam.
│   │   ├── Setting.php
│   │   ├── User.php                            Staff. Untouched framework model so far.
│   │   ├── Vehicle.php                         Bookings attach to these, not to classes.
│   │   ├── VehicleClass.php                    Carries the pricing.
│   │   └── VehicleHold.php                     Claims. Only VehicleHoldService writes here.
│   │
│   ├── Providers/
│   │   └── AppServiceProvider.php              Binds contracts to implementations;
│   │                                           enables Eloquent strict mode outside production.
│   │
│   └── Services/
│       ├── Availability/
│       │   └── AvailabilityService.php         Search. Groups candidates by turnaround buffer
│       │                                       so the date maths stays in PHP, not raw SQL.
│       ├── Holds/
│       │   └── VehicleHoldService.php          ★ The heart of the system. Row lock, re-check,
│       │                                       insert. Read this before changing anything
│       │                                       to do with bookings.
│       ├── Pricing/
│       │   └── PricingService.php              Resolves class → vehicle overrides. All money
│       │                                       is bcmath strings, never float.
│       └── Settings/
│           └── SettingsRepository.php          One cached snapshot of the settings table.
│
├── config/
│   └── carhire.php                             Display timezone, currency, money scale,
│                                               fallback turnaround buffer.
│
├── database/
│   ├── factories/                              One per model, with states for maintenance,
│   │                                           retired, released holds, lapsed holds and
│   │                                           price overrides.
│   ├── migrations/
│   │   ├── 0001_01_01_*                        Framework defaults (users, cache, jobs).
│   │   ├── 2026_08_03_000001_create_operators_table.php
│   │   ├── 2026_08_03_000002_create_branches_table.php
│   │   ├── 2026_08_03_000003_create_vehicle_classes_table.php
│   │   ├── 2026_08_03_000004_create_vehicles_table.php
│   │   ├── 2026_08_03_000005_create_vehicle_holds_table.php
│   │   ├── 2026_08_03_000006_create_settings_table.php
│   │   └── 2026_08_03_000007_create_audit_log_table.php   Includes the append-only triggers.
│   └── seeders/
│       ├── DatabaseSeeder.php                  Note the WithoutModelEvents warning inside.
│       ├── DemoFleetSeeder.php                 Sample fleet, local only. Every figure is a placeholder.
│       └── SettingsSeeder.php                  All spec §15 values. firstOrCreate, so re-seeding
│                                               never overwrites a real decision.
│
├── docs/                                       You are here.
│   ├── ARCHITECTURE.md
│   ├── CHANGELOG.md
│   ├── DEPLOYMENT.md
│   ├── DEVELOPER_GUIDE.md
│   ├── OPEN-ITEMS.md                           Outstanding business decisions. Check before launch.
│   ├── README.md
│   └── TREEVIEW.md
│
├── tests/
│   ├── Feature/
│   │   ├── AuditLogImmutabilityTest.php        Includes a raw-SQL test that proves the DB
│   │   │                                       trigger, not just the model guard.
│   │   ├── AvailabilityServiceTest.php         Buffer boundaries, released and lapsed holds,
│   │   │                                       and that search agrees with the single check.
│   │   ├── PricingServiceTest.php              Override chain and exact bcmath strings.
│   │   ├── SettingsRepositoryTest.php          Casting, caching, placeholder flagging.
│   │   ├── VehicleHoldConcurrencyTest.php      ★ Six real processes racing for one vehicle.
│   │   └── VehicleHoldServiceTest.php          Overlap rejection, self-healing, timezones.
│   ├── Unit/
│   │   └── DateRangeTest.php
│   └── TestCase.php
│
├── .env.example                                MySQL, UTC storage, Africa/Lusaka display.
├── phpunit.xml                                 Points at carhire_test on MySQL. The comment
│                                               inside explains why not SQLite.
└── README.md
```

## Not yet built

Phases 2 to 6 of the guideline's build order: the `bookings` table and state
machine, payment methods and adapters, the admin panel, the customer-facing UI,
notifications, KYC upload and cross-border. See CHANGELOG.md for what exists and
the developer guideline for what comes next.
