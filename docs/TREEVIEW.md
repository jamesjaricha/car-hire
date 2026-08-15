# Treeview

Annotated map of the parts of the codebase this project actually wrote.
Framework scaffolding that has not been meaningfully changed is summarised
rather than listed.

★ marks the files to read first if you are trying to understand how this works.

Last updated: Phase 4 in progress (booking screens, refunds, settings and fleet
pricing), 2026-08-09.

```
carhire/
├── app/
│   ├── Console/
│   │   ├── Commands/
│   │   │   ├── AttemptBookingCommand.php     Test harness. One whole checkout,
│   │   │   │                                 outcome as an exit code.
│   │   │   ├── AttemptPaymentConfirmationCommand.php
│   │   │   │                                 Test harness. One confirmation.
│   │   │   ├── AttemptRefundDisbursementCommand.php
│   │   │   │                                 Test harness. One payout attempt.
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
│   │   ├── Refund.php                        Its figures are FROZEN at request.
│   │   │                                     Never recompute them.
│   │   ├── RefundDisbursement.php        ★   The unique key here is what makes
│   │   │                                     double payout impossible.
│   │   ├── Setting.php
│   │   ├── User.php                          Staff, never customers. Roles, branch
│   │   │                                     and operator. Null branch = no counter.
│   │   ├── Vehicle.php                       Rate columns are nullable OVERRIDES.
│   │   ├── VehicleClass.php                  Carries the pricing.
│   │   └── VehicleHold.php                   Only VehicleHoldService writes here.
│   │
│   ├── Http/Controllers/                     The customer site. Thin by rule:
│   │   ├── BasketController.php          ★   Freezes the quote. Checkout reads
│   │   │                                     it back and never re-prices.
│   │   ├── CheckoutController.php        ★   Spec §1.3, and §1.4 — read the
│   │   │                                     header: it must not reveal that
│   │   │                                     an email already exists.
│   │   ├── HomeController.php                Cards are CLASSES, not one car
│   │   │                                     standing in for each. Read the
│   │   │                                     comment before reverting that.
│   │   ├── VehicleClassController.php    ★   Browse one class. QUOTES NOTHING —
│   │   │                                     no dates, so no hire, so no total.
│   │   │                                     A daily rate only, §1.2.
│   │   ├── SearchController.php          ★   availability is
│   │   │                                     AvailabilityService. Read its
│   │   │                                     header for the timezone edge —
│   │   │                                     inputs are wall-clock Lusaka,
│   │   │                                     everything stored is UTC.
│   │   └── VehicleController.php             Re-checks availability: a search
│   │                                         result is advisory.
│   │
│   ├── Filament/
│   │   ├── Pages/
│   │   │   └── ManageSettings.php        ★   The operator's control panel. Read
│   │   │                                     save(): it clears a placeholder
│   │   │                                     flag ONLY for fields that changed,
│   │   │                                     and that is load-bearing.
│   │   └── Resources/
│   │       ├── Bookings/                 ★   READ-ONLY. No form, no create or
│   │       │   │                             edit page. See BookingPolicy.
│   │       │   ├── BookingResource.php
│   │       │   ├── Actions/                  Every mutation lives here, and
│   │       │   │   ├── CancelAndRefundAction.php  each one calls a service.
│   │       │   │   │                         ★ One button, TWO services, one
│   │       │   │   │                         transaction. Neither knows the other.
│   │       │   │   ├── ExtendDeadlineAction.php   Domain exceptions become
│   │       │   │   └── TakeBalanceAction.php      notifications; anything else
│   │       │   │                             is left to bubble.
│   │       │   ├── Pages/
│   │       │   │   ├── ListBookings.php      Tabs. "Needs a decision" is the
│   │       │   │   │                         part-paid queue.
│   │       │   │   └── ViewBooking.php
│   │       │   ├── Schemas/BookingInfolist.php   The two deposits, kept apart.
│   │       │   └── Tables/BookingsTable.php
│   │       │
│   │       ├── PaymentMethods/               Edit only, Super Admin only. The
│   │       │   │                             six rows ARE the six enum cases —
│   │       │   │                             no create, no delete.
│   │       │   ├── PaymentMethodResource.php
│   │       │   ├── Pages/                    index, edit.
│   │       │   ├── Schemas/PaymentMethodForm.php ★
│   │       │   │                             Two rules do the work: required
│   │       │   │                             account details, and every
│   │       │   │                             :placeholder being fillable.
│   │       │   └── Tables/PaymentMethodsTable.php
│   │       │                                 "Offered to customers" ≠ "switched
│   │       │                                 on".
│   │       │
│   │       ├── VehicleClasses/               REAL FORMS — the only resource with
│   │       │   │                             them. A class is a row of figures
│   │       │   │                             nobody's service owns. See
│   │       │   │                             ARCHITECTURE §11 for the line.
│   │       │   ├── VehicleClassResource.php
│   │       │   ├── Pages/                    index, create, edit. No delete.
│   │       │   ├── Schemas/VehicleClassForm.php ★
│   │       │   │                             The three §15 fields are nullable
│   │       │   │                             and NOT required: empty means
│   │       │   │                             undecided, and requiring a number
│   │       │   │                             would invent one.
│   │       │   └── Tables/VehicleClassesTable.php
│   │       │                                 "Sellable" is not decoration. The
│   │       │                                 Photos column is `warning` and
│   │       │                                 never `danger` — no photograph
│   │       │                                 still sells, a missing §15 figure
│   │       │                                 does not.
│   │       │
│   │       ├── Vehicles/                     REAL FORMS. The physical fleet.
│   │       │   │                             `fleet.manage-vehicles`, Branch
│   │       │   │                             Manager and above — a car is local
│   │       │   │                             where a class price list is not.
│   │       │   ├── VehicleResource.php       Badge counts cars off the road.
│   │       │   ├── Pages/                    index, create, edit. No delete.
│   │       │   ├── Schemas/VehicleForm.php ★
│   │       │   │                             READ THIS BEFORE EDITING. The two
│   │       │   │                             money fields are nullable
│   │       │   │                             OVERRIDES — empty means inherit,
│   │       │   │                             never zero — and they are disabled
│   │       │   │                             AND undehydrated without
│   │       │   │                             `fleet.manage`, so a manager's
│   │       │   │                             save cannot clear a price.
│   │       │   └── Tables/VehiclesTable.php  "Class rate" ≠ a decision made here.
│   │       │
│   │       └── Refunds/                  ★   READ-ONLY, and for stronger reasons
│   │           │                             than bookings: the amount is locked,
│   │           │                             the approver is subject to §9.3, and
│   │           │                             the status derives from a unique key.
│   │           ├── RefundResource.php        Navigation badge = the open queues.
│   │           ├── Actions/
│   │           │   ├── ApproveRefundAction.php    Hidden from the requester.
│   │           │   ├── DisburseRefundAction.php   No amount field, by design.
│   │           │   └── RejectRefundAction.php     Reason required.
│   │           ├── Pages/
│   │           │   ├── ListRefunds.php       Two queue tabs, both first.
│   │           │   └── ViewRefund.php
│   │           ├── Schemas/RefundInfolist.php    Shows the §9 working, not just
│   │           │                                 the answer.
│   │           └── Tables/RefundsTable.php
│   │
│   ├── Policies/
│   │   ├── BookingPolicy.php             ★   create/update/delete all false.
│   │   │                                     This is what makes read-only real.
│   │   ├── RefundPolicy.php              ★   Same, and higher stakes: a form here
│   │   │                                     could defeat the two-person rule.
│   │   ├── VehiclePolicy.php             ★   Real CRUD, narrower permission
│   │   │                                     than the class policy. Read the
│   │   │                                     docblock for why "may edit a
│   │   │                                     vehicle" is not "may price one".
│   │   └── VehicleClassPolicy.php            The exception: real CRUD, because
│   │                                         no service owns these writes.
│   │                                         Delete still refused — classes are
│   │                                         retired, and history reads through
│   │                                         them.
│   │
│   ├── Providers/
│   │   ├── AppServiceProvider.php            Contract bindings; Eloquent strict mode.
│   │   └── Filament/
│   │       └── AdminPanelProvider.php    ★   /admin. Read its docblock BEFORE
│   │                                         adding any resource.
│   │
│   ├── Services/
│   │   ├── Audit/AuditLogger.php              ★   Sole writer to audit_log.
│   │   ├── Availability/AvailabilityService.php   Search. Results are advisory.
│   │   ├── Basket/BasketService.php               Session basket, frozen price.
│   │   ├── Bookings/
│   │   │   ├── BookingCancellationService.php     A PERSON ending a booking, as
│   │   │   │                                      the counterpart to the expiry
│   │   │   │                                      sweep's clock. Releases the hold.
│   │   │   ├── BookingCreationService.php     ★   Where everything meets, in one
│   │   │   │                                      transaction. Lock ordering matters.
│   │   │   ├── BookingExpiryService.php           Spec §8.4. One transaction per
│   │   │   │                                      booking; part-paid ones skipped.
│   │   │   ├── BookingLedger.php              ★   The SINGLE owner of amount_paid:
│   │   │   │                                      confirmed receipts − disbursed
│   │   │   │                                      refunds. Two services write that
│   │   │   │                                      figure; only this works it out.
│   │   │   ├── BookingReferenceGenerator.php      Gapless BR-00001, under a lock.
│   │   │   └── BookingStateMachine.php            Spec §7.3, transcribed. Asserts
│   │   │                                          only — it never persists.
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
│   │   │   ├── CounterPaymentService.php          Money handed over in person:
│   │   │   │                                      record + confirm, one txn.
│   │   │   ├── PaymentAdapterResolver.php         Cards resolve to a refusal.
│   │   │   ├── PaymentConfirmationService.php ★   Where money becomes real. The
│   │   │   │                                      unique key is the guarantee,
│   │   │   │                                      not the check inside it.
│   │   │   ├── PaymentDeadlineCalculator.php      Spec §8.2, incl. short notice.
│   │   │   ├── PaymentDeadlineExtensionService.php  Moves the deadline AND the
│   │   │   │                                        hold. Never one alone.
│   │   │   ├── PaymentMethodService.php           Refuses disabled methods server-side.
│   │   │   ├── PaymentRecordingService.php    ★   Raises receipts. Touches the
│   │   │   │                                      booking's totals never.
│   │   │   └── PaymentReferenceGenerator.php      BR-00001-1, and UP-00001 for
│   │   │                                          receipts with no booking.
│   │   ├── References/ReferenceSequence.php       The locked counter both
│   │   │                                          reference series share.
│   │   ├── Refunds/                               Spec §9. Split because §9.3
│   │   │   │                                      splits the people.
│   │   │   ├── RefundCalculator.php           ★   Pure. (booking, reason) → what
│   │   │   │                                      is owed. Deposit first, then the
│   │   │   │                                      fee on what is left; all clamped.
│   │   │   ├── RefundDisbursementService.php  ★   Money leaving. The unique key is
│   │   │   │                                      the guarantee, not the check.
│   │   │   └── RefundRequestService.php           Request, approve, reject. The
│   │   │                                          two-person rule lives here AND
│   │   │                                          in a CHECK constraint.
│   │   ├── Pricing/
│   │   │   ├── PricingService.php                 Class → vehicle override chain.
│   │   │   └── QuoteService.php                   Search price == checkout price.
│   │   └── Settings/SettingsRepository.php        Cached, typed configuration.
│   │
│   └── Support/
│       └── Money.php                      ★   All monetary arithmetic. Rounds half
│                                              up; bcmath alone truncates.
│
├── resources/
│   ├── css/app.css                       ★   Design tokens. Booking blue, the
│   │                                         Zambian flag colours as accents,
│   │                                         and a separate `ink` scale for the
│   │                                         money surfaces. Read the header.
│   └── views/
│       ├── layouts/site.blade.php            Public layout. Skip link, fonts,
│       │                                     explicit focus rings on the dark
│       │                                     header (the UA default reads
│       │                                     inconsistently there).
│       ├── home.blade.php                    Hero, fleet, how booking works.
│       ├── search.blade.php                  Results, grouped by class.
│       ├── vehicle.blade.php                 One vehicle, itemised price, both
│       │                                     deposits named separately. Renders
│       │                                     any $errors from a raw POST to
│       │                                     basket.store — not reachable
│       │                                     through its own hidden-field form,
│       │                                     fixed for parity anyway.
│       ├── checkout.blade.php                Three fields (§1.3), pay-in-full
│       │                                     choice (§5), method (§8.2).
│       ├── confirmation.blade.php        ★   The screen a customer stares at
│       │                                     while deciding to send money.
│       │                                     Never says "confirmed" — §7.3.
│       │                                     The copy-reference label is an
│       │                                     aria-live region: the visual
│       │                                     "Copied" swap (app.js) needed a
│       │                                     screen-reader announcement too.
│       └── components/
│           ├── search-form.blade.php         Plain GET. Shareable URL, works
│           │                                 without JavaScript. Every field
│           │                                 renders its own error, not just
│           │                                 `dates` — a hand-altered URL can
│           │                                 fail branch/pickup/dropoff too.
│           └── vehicle-card.blade.php    ★   Built to work WITHOUT a
│                                             photograph. Silhouette fallback;
│                                             §6 deposit shown here because it
│                                             must never first appear at the
│                                             counter.
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
│   │   ├── 2026_08_05_000006_*                payment method and proof columns
│   │   │                                      on audit_log.
│   │   ├── 2026_08_08_00000{1,2}_*        ★   refunds (+ the two-person CHECK
│   │   │                                      constraint) and refund_disbursements
│   │   │                                      (+ the UNIQUE refund_id that makes
│   │   │                                      double payout impossible).
│   │   └── 2026_08_09_000001_*           ★   vehicle class pricing made nullable.
│   │                                          NULL = undecided, 0.00 = decided
│   │                                          and zero. Read its header — an
│   │                                          undecided class was publishing
│   │                                          "no deposit required" to customers.
│   └── seeders/
│       ├── DatabaseSeeder.php                 Note the WithoutModelEvents warning.
│       ├── DemoFleetSeeder.php            ★   Local only. Every figure a
│       │                                      placeholder. Table-driven: 6
│       │                                      classes, 18 vehicles, 2 branches.
│       │                                      firstOrCreate throughout, so
│       │                                      re-seeding never discards a
│       │                                      photograph uploaded in the panel.
│       ├── DemoStaffSeeder.php                Local only, and throws elsewhere.
│       │                                      One account per role, plus a
│       │                                      roleless one that must be refused.
│       ├── DemoPaymentDetailsSeeder.php   ★   Local and TEST only, refuses in
│       │                                      production. Fake account details,
│       │                                      because a method with none is now
│       │                                      withheld from customers and real
│       │                                      numbers must never be seeded.
│       ├── PaymentMethodSeeder.php            Spec §3 and §8.1 hold durations.
│       │                                      Seeds NO account details, on
│       │                                      purpose — see above.
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
│   │   ├── Filament/
│   │   │   ├── BookingResourceTest.php    ★   Proves read-only is enforced,
│   │   │   │                                  not merely intended.
│   │   │   ├── ManageSettingsTest.php     ★   Read the placeholder-flag tests:
│   │   │   │                                  they guard the mechanism every
│   │   │   │                                  §15 warning depends on.
│   │   │   ├── PaymentMethodResourceTest.php  Required details, and templates
│   │   │   │                                  that would print :placeholders
│   │   │   │                                  to a customer verbatim.
│   │   │   ├── RefundResourceTest.php     ★   The same, plus §9.3 made visible
│   │   │   │                                  and the cancel-and-refund path.
│   │   │   └── VehicleClassResourceTest.php   Creating a class without
│   │   │                                      inventing its §15 figures.
│   │   ├── AdminPanelAccessTest.php           Who may open /admin at all.
│   │   ├── AuditLogImmutabilityTest.php       Proves the DB trigger, not the model.
│   │   ├── AuditLoggerTest.php                Every field §12 demands, in one
│   │   │                                      entry.
│   │   ├── AvailabilityServiceTest.php
│   │   ├── BasketServiceTest.php              Price frozen against a rate change.
│   │   ├── DemoFleetSeederTest.php        ★   Development data, tested because
│   │   │                                     every way it breaks is silent: an
│   │   │                                     unpriced class vanishes from search,
│   │   │                                     and a non-idempotent seeder eats
│   │   │                                     photographs uploaded in the panel.
│   │   ├── BookingCancellationServiceTest.php Endings, and the hold release the
│   │   │                                      revenue leak depended on.
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
│   │   ├── RefundCalculatorTest.php       ★   Spec §9's every rule against every
│   │   │                                      timing. The exhaustive one — this is
│   │   │                                      where a mistake is somebody's money.
│   │   ├── RefundDisbursementConcurrencyTest.php ★
│   │   │                                      Four processes, one refund. The most
│   │   │                                      consequential of the four races.
│   │   ├── RefundDisbursementServiceTest.php  Payout, the ledger, and the refusals.
│   │   ├── RefundRequestServiceTest.php       §9.3's two-person rule, tested via
│   │   │                                      the service AND against the raw table.
│   │   ├── RolesAndPermissionsSeederTest.php  Transcribes the §12 matrix
│   │   │                                      independently of the enum.
│   │   ├── StaffUserTest.php                  Branch posting, roles, cash exemption.
│   │   ├── VehicleClassPricingDecisionsTest.php ★
│   │   │                                      NULL vs 0.00, and what search does
│   │   │                                      with a class nobody has priced.
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

Phases 1 to 3 are complete, and Phase 5 (search through confirmation) is
complete and demonstrable at `carhire.test`. Phase 4's back office is
deliberately paused mid-way — see the direction change in `project_carhire.md`
and `CHANGELOG.md` — while the operator sees what exists and decides whether
these modules are what he wants.

**Phase 4 — Admin, paused mid-way.** Done: the access gate, the booking screens
with the part-paid-past-deadline queue, deadline extension, counter payments,
refunds end to end (§9), settings and vehicle-class pricing, and payment
methods. Parked until after the demo: the payments resource and the unmatched
queue, vehicles/branches CRUD, users and roles UI, and vehicle reassignment
(§8.3).

**Phase 5 — Customer UI. Complete.** Search, vehicle detail, basket, guest
checkout, confirmation. Account linking (as opposed to guest checkout) is not
built — §1.4 only requires that a match never link silently, not that linking
exist yet.

**Phase 6 — Notifications, KYC upload, cross-border.** Not started. Includes
the payment reminder of spec §8.4 and the cross-border tables that turn
`awaiting_cross_border` into a workflow rather than a state.

Carried forward and recorded in OPEN-ITEMS.md: releasing a hold when a car
comes back early, and the parked Phase 4 screens above.
