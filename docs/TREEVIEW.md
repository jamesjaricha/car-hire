# Treeview

Annotated map of the parts of the codebase this project actually wrote.
Framework scaffolding that has not been meaningfully changed is summarised
rather than listed.

★ marks the files to read first if you are trying to understand how this works.

Last updated: Phase 3 in progress (roles and permissions landed), 2026-08-05.

```
carhire/
├── app/
│   ├── Console/
│   │   ├── Commands/
│   │   │   ├── AttemptBookingCommand.php     Test harness. One whole checkout,
│   │   │   │                                 outcome as an exit code.
│   │   │   └── AttemptVehicleHoldCommand.php Test harness. One hold attempt.
│   │   └── Concerns/
│   │       └── WaitsForBarrier.php           Holds spawned processes at a shared
│   │                                         instant so contention is real.
│   │
│   ├── Contracts/                            Interfaces for every domain service.
│   │   ├── AvailabilityServiceContract.php   ADVISORY ONLY — read the note inside.
│   │   ├── BasketServiceContract.php
│   │   ├── BookingCreationServiceContract.php
│   │   ├── BookingReferenceGeneratorContract.php
│   │   ├── BookingStateMachineContract.php
│   │   ├── CustomerResolverContract.php      Spec §1.4, security-critical.
│   │   ├── PaymentDeadlineCalculatorContract.php
│   │   ├── PaymentMethodServiceContract.php
│   │   ├── PhoneNormaliserContract.php
│   │   ├── PricingServiceContract.php
│   │   ├── QuoteServiceContract.php
│   │   ├── SettingsRepositoryContract.php
│   │   └── VehicleHoldServiceContract.php    The only sanctioned way to claim a car.
│   │
│   ├── DataTransferObjects/
│   │   ├── Basket.php                        Guest basket, flattened for the session.
│   │   ├── BookingCreationResult.php         `hold` is null for short notice.
│   │   ├── BookingRequest.php                Carries no totals — those are derived.
│   │   ├── CustomerDetails.php               The three fields checkout asks for.
│   │   ├── CustomerResolutionResult.php      ⚠ its match flag must never reach the UI.
│   │   ├── DateRange.php                     Half-open [start, end); buffer padding.
│   │   ├── NormalisedPhone.php
│   │   ├── PaymentWindow.php                 Null deadline means "pay at the counter".
│   │   ├── Quote.php                     ★   The one price, shown everywhere.
│   │   ├── QuoteOptions.php                  Extras and cross-border, as totals.
│   │   ├── SearchCriteria.php                Outlives the basket, on purpose.
│   │   └── TransitionContext.php             The facts a state guard checks.
│   │
│   ├── Enums/
│   │   ├── BookingStatus.php                 Spec §7.2. `Basket` never persists.
│   │   ├── CustomerResolutionOutcome.php
│   │   ├── InsurancePriceMode.php            per_day | flat
│   │   ├── PaymentMethodCode.php             The six methods of spec §3.
│   │   ├── PaymentMethodType.php             Type drives behaviour, not the name.
│   │   ├── SettingKey.php                    Every operator-editable value.
│   │   ├── StaffPermission.php               Spec §12, verbatim. 15 permissions.
│   │   ├── StaffRole.php                     The §12 matrix as grants.
│   │   ├── TransitionActor.php               customer | staff | system
│   │   └── VehicleStatus.php                 available | maintenance | retired
│   │
│   ├── Exceptions/
│   │   ├── AuditLogImmutableException.php     Reaching this is always a bug.
│   │   ├── BookingNotPossibleException.php    A malformed request.
│   │   ├── InvalidBookingTransitionException.php
│   │   ├── InvalidDateRangeException.php
│   │   ├── PaymentMethodNotAvailableException.php
│   │   └── VehicleNotAvailableException.php   Losing a race. Expected, not a fault.
│   │
│   ├── Models/
│   │   ├── AuditLogEntry.php                 Append-only. Refuses update and delete.
│   │   ├── Booking.php                       Never set `status` directly.
│   │   ├── BookingReferenceCounter.php       Read only via the generator.
│   │   ├── Branch.php
│   │   ├── Customer.php                      Duplicates are permitted by design.
│   │   ├── Operator.php                      The multi-operator seam.
│   │   ├── PaymentMethod.php                 Use isOfferable(), not `enabled`.
│   │   ├── Setting.php
│   │   ├── User.php                          Staff, never customers. Roles, branch
│   │   │                                     and operator. Null branch = no counter.
│   │   ├── Vehicle.php                       Rate columns are nullable OVERRIDES.
│   │   ├── VehicleClass.php                  Carries the pricing.
│   │   └── VehicleHold.php                   Only VehicleHoldService writes here.
│   │
│   ├── Providers/
│   │   └── AppServiceProvider.php            Contract bindings; Eloquent strict mode.
│   │
│   ├── Services/
│   │   ├── Availability/AvailabilityService.php   Search. Results are advisory.
│   │   ├── Basket/BasketService.php               Session basket, frozen price.
│   │   ├── Bookings/
│   │   │   ├── BookingCreationService.php     ★   Where everything meets, in one
│   │   │   │                                      transaction. Lock ordering matters.
│   │   │   ├── BookingReferenceGenerator.php      Gapless BR-00001, under a lock.
│   │   │   └── BookingStateMachine.php            Spec §7.3, transcribed.
│   │   ├── Customers/
│   │   │   ├── CustomerResolver.php               A match NEVER links silently.
│   │   │   └── PhoneNormaliser.php                E.164 via libphonenumber.
│   │   ├── Holds/VehicleHoldService.php       ★   The heart of the system. Read the
│   │   │                                          comments before changing anything.
│   │   ├── Payments/
│   │   │   ├── PaymentDeadlineCalculator.php      Spec §8.2, incl. short notice.
│   │   │   └── PaymentMethodService.php           Refuses disabled methods server-side.
│   │   ├── Pricing/
│   │   │   ├── PricingService.php                 Class → vehicle override chain.
│   │   │   └── QuoteService.php                   Search price == checkout price.
│   │   └── Settings/SettingsRepository.php        Cached, typed configuration.
│   │
│   └── Support/
│       └── Money.php                      ★   All monetary arithmetic. Rounds half
│                                              up; bcmath alone truncates.
│
├── config/
│   ├── carhire.php                            Timezone, currency, phone region,
│   │                                          reference format, payment flags.
│   ├── database.php                           ⚠ READ COMMITTED is deliberate and
│   │                                          load-bearing. See the comment.
│   └── permission.php                         Written, not published. Teams and
│                                              wildcards off, both by decision.
│
├── database/
│   ├── factories/                             One per model, with states.
│   ├── migrations/
│   │   ├── 0001_01_01_*                       Framework defaults.
│   │   ├── 2026_08_03_00000{1..7}_*           operators, branches, vehicle_classes,
│   │   │                                      vehicles, vehicle_holds, settings,
│   │   │                                      audit_log (+ append-only triggers).
│   │   ├── 2026_08_03_00000{8,9}_*            customers, payment_methods.
│   │   └── 2026_08_04_00000{1..3}_*           booking_reference_counters, bookings,
│   │                                          and the vehicle_holds foreign key.
│   │   └── 2026_08_05_00000{1,2}_*            the five permission tables, and
│   │                                          operator_id + branch_id on users.
│   └── seeders/
│       ├── DatabaseSeeder.php                 Note the WithoutModelEvents warning.
│       ├── DemoFleetSeeder.php                Local only. Every figure a placeholder.
│       ├── PaymentMethodSeeder.php            Spec §3 and §8.1 hold durations.
│       ├── RolesAndPermissionsSeeder.php  ★   Spec §12. Authoritative — re-running
│       │                                      it revokes off-matrix grants. Reads
│       │                                      nothing through the permission cache.
│       └── SettingsSeeder.php                 All §15 values; firstOrCreate.
│
├── docs/                                      You are here.
│   ├── ARCHITECTURE.md                    ★   Why it is built this way.
│   ├── CHANGELOG.md
│   ├── DEPLOYMENT.md
│   ├── DEVELOPER_GUIDE.md                 ★   Read before writing code.
│   ├── OPEN-ITEMS.md                          Outstanding business decisions.
│   ├── README.md
│   └── TREEVIEW.md
│
├── tests/
│   ├── Feature/
│   │   ├── AuditLogImmutabilityTest.php       Proves the DB trigger, not the model.
│   │   ├── AvailabilityServiceTest.php
│   │   ├── BasketServiceTest.php              Price frozen against a rate change.
│   │   ├── BookingConcurrencyTest.php     ★   Real processes racing a whole checkout.
│   │   ├── BookingCreationServiceTest.php
│   │   ├── BookingReferenceGeneratorTest.php
│   │   ├── CustomerResolverTest.php           Spec §1.4 in full.
│   │   ├── PaymentDeadlineCalculatorTest.php
│   │   ├── PaymentMethodServiceTest.php
│   │   ├── PricingServiceTest.php
│   │   ├── QuoteServiceTest.php
│   │   ├── RolesAndPermissionsSeederTest.php  Transcribes the §12 matrix
│   │   │                                      independently of the enum.
│   │   ├── StaffUserTest.php                  Branch posting, roles, cash exemption.
│   │   ├── VehicleHoldConcurrencyTest.php ★   Real processes racing one vehicle.
│   │   └── VehicleHoldServiceTest.php
│   ├── Unit/
│   │   ├── BookingStateMachineTest.php        Transcribes §7.3 independently.
│   │   ├── DateRangeTest.php
│   │   ├── MoneyTest.php
│   │   ├── PhoneNormaliserTest.php
│   │   └── StaffPermissionTest.php            Method → confirmation permission.
│   └── TestCase.php
│
├── .env.example
├── phpunit.xml                                MySQL, not SQLite. The comment says why.
└── README.md
```

## Not yet built

Phase 3 is under way. Roles and permissions exist; payment records, references,
staff confirmation, the audit writer and the expiry job do not yet.

Phases 4 to 6 are untouched: the admin panel, the customer-facing UI,
notifications, KYC upload and cross-border. See CHANGELOG.md for what exists.
