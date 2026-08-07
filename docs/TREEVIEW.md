# Treeview

Annotated map of the parts of the codebase this project actually wrote.
Framework scaffolding that has not been meaningfully changed is summarised
rather than listed.

★ marks the files to read first if you are trying to understand how this works.

Last updated: end of Phase 3, 2026-08-05.

```
carhire/
├── app/
│   ├── Console/
│   │   ├── Commands/
│   │   │   ├── AttemptBookingCommand.php     Test harness. One whole checkout,
│   │   │   │                                 outcome as an exit code.
│   │   │   ├── AttemptPaymentConfirmationCommand.php
│   │   │   │                                 Test harness. One confirmation.
│   │   │   ├── AttemptVehicleHoldCommand.php Test harness. One hold attempt.
│   │   │   └── ExpireBookingsCommand.php ★   NOT a harness. Runs in production,
│   │   │                                     every 5 minutes, and by hand.
│   │   └── Concerns/
│   │       └── WaitsForBarrier.php           Holds spawned processes at a shared
│   │                                         instant so contention is real.
│   │
│   ├── Contracts/                            Interfaces for every domain service.
│   │   ├── AuditLoggerContract.php           The only sanctioned writer to
│   │   │                                     audit_log. Call it in-transaction.
│   │   ├── AvailabilityServiceContract.php   ADVISORY ONLY — read the note inside.
│   │   ├── BasketServiceContract.php
│   │   ├── BookingCreationServiceContract.php
│   │   ├── BookingReferenceGeneratorContract.php
│   │   ├── BookingStateMachineContract.php
│   │   ├── CustomerResolverContract.php      Spec §1.4, security-critical.
│   │   ├── PaymentDeadlineCalculatorContract.php
│   │   ├── PaymentAdapterContract.php        Spec §4. Only what the four
│   │   │                                     offline providers differ on.
│   │   ├── PaymentAdapterResolverContract.php
│   │   ├── PaymentMethodServiceContract.php
│   │   ├── PaymentRecordingServiceContract.php   Writes receipts. Never
│   │   │                                         confirms them.
│   │   ├── PaymentReferenceGeneratorContract.php
│   │   ├── PhoneNormaliserContract.php
│   │   ├── PricingServiceContract.php
│   │   ├── QuoteServiceContract.php
│   │   ├── ReferenceSequenceContract.php     Gapless counter, keyed by prefix.
│   │   ├── SettingsRepositoryContract.php
│   │   └── VehicleHoldServiceContract.php    The only sanctioned way to claim a car.
│   │
│   ├── DataTransferObjects/
│   │   ├── AuditEntry.php                    Holds values, converts nothing.
│   │   │                                     is_automatic is DERIVED from actor.
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
│   │   ├── AuditAction.php                   Spec §12's audited actions. Some
│   │   │                                     are declared ahead of their phase.
│   │   ├── BookingPaymentStatus.php          Spec §7.1 verbatim. DERIVED — never
│   │   │                                     assign it; recompute it.
│   │   ├── BookingStatus.php                 Spec §7.2. `Basket` never persists.
│   │   ├── CustomerResolutionOutcome.php
│   │   ├── InsurancePriceMode.php            per_day | flat
│   │   ├── PaymentMethodCode.php             The six methods of spec §3.
│   │   ├── PaymentMethodType.php             Type drives behaviour, not the name.
│   │   ├── PaymentStatus.php                 ONE receipt's lifecycle. Not §7.1 —
│   │   │                                     see BookingPaymentStatus.
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
│   │   ├── Payment.php                       One receipt. Null booking_id is the
│   │   │                                     unmatched queue, by design.
│   │   ├── PaymentConfirmation.php       ★   The unique key here is what makes
│   │   │                                     double confirmation impossible.
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
│   │   ├── Audit/AuditLogger.php              ★   Sole writer to audit_log.
│   │   ├── Availability/AvailabilityService.php   Search. Results are advisory.
│   │   ├── Basket/BasketService.php               Session basket, frozen price.
│   │   ├── Bookings/
│   │   │   ├── BookingCreationService.php     ★   Where everything meets, in one
│   │   │   │                                      transaction. Lock ordering matters.
│   │   │   ├── BookingExpiryService.php           Spec §8.4. One transaction per
│   │   │   │                                      booking; part-paid ones skipped.
│   │   │   ├── BookingReferenceGenerator.php      Gapless BR-00001, under a lock.
│   │   │   └── BookingStateMachine.php            Spec §7.3, transcribed.
│   │   ├── Customers/
│   │   │   ├── CustomerResolver.php               A match NEVER links silently.
│   │   │   └── PhoneNormaliser.php                E.164 via libphonenumber.
│   │   ├── Holds/VehicleHoldService.php       ★   The heart of the system. Read the
│   │   │                                          comments before changing anything.
│   │   ├── Payments/
│   │   │   ├── Adapters/                          One per offline provider.
│   │   │   │   ├── OfflinePaymentAdapter.php      Merge fields; account checks.
│   │   │   │   ├── CashAdapter.php                Needs no configuration.
│   │   │   │   ├── BankTransferAdapter.php
│   │   │   │   ├── MtnMomoAdapter.php             NOT a gateway. Spec §3.1.
│   │   │   │   └── AirtelMoneyAdapter.php
│   │   │   ├── PaymentAdapterResolver.php         Cards resolve to a refusal.
│   │   │   ├── PaymentConfirmationService.php ★   Where money becomes real. The
│   │   │   │                                      unique key is the guarantee,
│   │   │   │                                      not the check inside it.
│   │   │   ├── PaymentDeadlineCalculator.php      Spec §8.2, incl. short notice.
│   │   │   ├── PaymentMethodService.php           Refuses disabled methods server-side.
│   │   │   ├── PaymentRecordingService.php    ★   Raises receipts. Touches the
│   │   │   │                                      booking's totals never.
│   │   │   └── PaymentReferenceGenerator.php      BR-00001-1, and UP-00001 for
│   │   │                                          receipts with no booking.
│   │   ├── References/ReferenceSequence.php       The locked counter both
│   │   │                                          reference series share.
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
│   │   ├── 2026_08_05_00000{1,2}_*            the five permission tables, and
│   │   │                                      operator_id + branch_id on users.
│   │   ├── 2026_08_05_00000{3..5}_*           bookings.payment_status, payments,
│   │   │                                      payment_confirmations (unique key).
│   │   └── 2026_08_05_000006_*                payment method and proof columns
│   │                                          on audit_log.
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
│   │   ├── AuditLoggerTest.php                Every field §12 demands, in one
│   │   │                                      entry.
│   │   ├── AvailabilityServiceTest.php
│   │   ├── BasketServiceTest.php              Price frozen against a rate change.
│   │   ├── BookingConcurrencyTest.php     ★   Real processes racing a whole checkout.
│   │   ├── BookingCreationServiceTest.php
│   │   ├── BookingExpiryServiceTest.php       Read the note on the race test:
│   │   │                                      it proves less than it looks.
│   │   ├── BookingReferenceGeneratorTest.php
│   │   ├── CustomerResolverTest.php           Spec §1.4 in full.
│   │   ├── PaymentDeadlineCalculatorTest.php
│   │   ├── PaymentMethodServiceTest.php
│   │   ├── PaymentAdapterTest.php             Resolution, configuration and
│   │   │                                      merge-field rendering.
│   │   ├── PaymentConfirmationConcurrencyTest.php ★
│   │   │                                      Four processes, one payment. The
│   │   │                                      test the phase was built around.
│   │   ├── PaymentConfirmationServiceTest.php Thresholds, refusals, the hold.
│   │   ├── PaymentModelTest.php               Proves the double-confirmation
│   │   │                                      constraint at the database.
│   │   ├── PaymentRecordingServiceTest.php    Raising, unmatched receipts,
│   │   │                                      and every refusal.
│   │   ├── PaymentReferenceGeneratorTest.php  Suffixes, gaps, and the two series.
│   │   ├── PricingServiceTest.php
│   │   ├── QuoteServiceTest.php
│   │   ├── RolesAndPermissionsSeederTest.php  Transcribes the §12 matrix
│   │   │                                      independently of the enum.
│   │   ├── StaffUserTest.php                  Branch posting, roles, cash exemption.
│   │   ├── VehicleHoldConcurrencyTest.php ★   Real processes racing one vehicle.
│   │   └── VehicleHoldServiceTest.php
│   ├── Unit/
│   │   ├── BookingPaymentStatusTest.php       Transcribes §7.1 independently.
│   │   ├── BookingStateMachineTest.php        Transcribes §7.3 independently.
│   │   ├── DateRangeTest.php
│   │   ├── MoneyTest.php
│   │   ├── PaymentStatusTest.php              What counts as money in hand.
│   │   ├── PhoneNormaliserTest.php
│   │   └── StaffPermissionTest.php            Method → confirmation permission.
│   └── TestCase.php
│
├── .env.example
├── phpunit.xml                                MySQL, not SQLite. The comment says why.
└── README.md
```

## Not yet built

Phases 1 to 3 are complete. A booking can be searched for, priced, held, taken,
paid for, confirmed and expired, and every consequential step is audited. There
is still no user interface of any kind.

**Phase 4 — Admin.** Roles UI, payment confirmation screens, the unmatched
payments queue, the part-paid-past-deadline queue, vehicle reassignment,
deadline extension, and the `refunds` table with its two-person request and
approve workflow.

**Phase 5 — Customer UI.** Search, vehicle detail, basket, checkout,
confirmation. Guest flow first, account linking second.

**Phase 6 — Notifications, KYC upload, cross-border.** Including the payment
reminder of spec §8.4 and the cross-border tables that turn
`awaiting_cross_border` into a workflow rather than a state.

Carried into Phase 4 and recorded in OPEN-ITEMS.md: releasing a hold when a car
comes back early, and the screens for the two queues above.

Phases 4 to 6 are untouched: the admin panel, the customer-facing UI,
notifications, KYC upload and cross-border. See CHANGELOG.md for what exists.
