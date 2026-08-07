<?php

declare(strict_types=1);

namespace App\Services\Bookings;

use App\Contracts\BookingCreationServiceContract;
use App\Contracts\BookingReferenceGeneratorContract;
use App\Contracts\CustomerResolverContract;
use App\Contracts\PaymentDeadlineCalculatorContract;
use App\Contracts\PaymentMethodServiceContract;
use App\Contracts\PaymentRecordingServiceContract;
use App\Contracts\QuoteServiceContract;
use App\Contracts\VehicleHoldServiceContract;
use App\DataTransferObjects\BookingCreationResult;
use App\DataTransferObjects\BookingRequest;
use App\Enums\BookingPaymentStatus;
use App\Enums\BookingStatus;
use App\Exceptions\BookingNotPossibleException;
use App\Models\Booking;
use App\Models\VehicleHold;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Where the whole booking engine meets.
 *
 * ORDER OF OPERATIONS IS DELIBERATE
 *
 * The vehicle is locked (by placing the hold) BEFORE the booking row is
 * inserted. That looks backwards — the hold wants a booking_id — but inserting
 * first would take a shared row lock on the vehicle for the foreign key check,
 * and then upgrading that to the exclusive lock the hold needs. Two concurrent
 * checkouts on the same vehicle would each hold a shared lock while waiting for
 * the other to release it: a textbook deadlock. Taking the exclusive lock first
 * gives every transaction the same lock order, so they queue instead.
 *
 * The hold is linked to the booking immediately afterwards, inside the same
 * transaction, so the two are never separately visible.
 *
 * ALL OR NOTHING
 *
 * A failure anywhere rolls back everything: no orphaned customer record, no
 * stranded hold quietly keeping a vehicle off sale, and the booking reference
 * returns to the sequence.
 */
final class BookingCreationService implements BookingCreationServiceContract
{
    public function __construct(
        private readonly PaymentMethodServiceContract $paymentMethods,
        private readonly PaymentDeadlineCalculatorContract $deadlines,
        private readonly QuoteServiceContract $quotes,
        private readonly CustomerResolverContract $customers,
        private readonly VehicleHoldServiceContract $holds,
        private readonly BookingReferenceGeneratorContract $references,
        private readonly PaymentRecordingServiceContract $payments,
    ) {}

    public function create(BookingRequest $request): BookingCreationResult
    {
        $now = CarbonImmutable::now();

        // Cheap structural checks first, outside the transaction. No point
        // taking locks to discover the pickup date is in the past.
        $this->assertPickupIsInTheFuture($request, $now);
        $this->assertBranchesBelongToTheVehiclesOperator($request);

        // Loaded explicitly rather than left to a lazy load inside the
        // transaction, where an unexpected query is harder to see.
        $request->vehicle->loadMissing('vehicleClass');

        return DB::transaction(function () use ($request, $now): BookingCreationResult {
            $pickupAt = $request->range->start;

            // 1. The payment method is validated HERE, where it is used —
            //    not where the form was drawn. Spec §14.2.
            $method = $this->paymentMethods->assertSelectable(
                $request->paymentMethodCode,
                $pickupAt,
                $now,
            );

            // 2. Deadline, and whether a vehicle is held at all.
            $window = $this->deadlines->calculate($method, $pickupAt, $now);

            // 3. Price. The same service search used, so the numbers match.
            $quote = $this->quotes->quoteFor(
                $request->vehicle,
                $request->range,
                $request->quoteOptions,
            );

            // 4. Customer. Never links silently — see CustomerResolver.
            $resolution = $this->customers->resolveForCheckout(
                $request->customer,
                $request->verifiedCustomer,
            );

            // 5. Claim the vehicle. Throws if someone got there first, which
            //    rolls back everything above.
            $hold = $window->placesHold
                ? $this->holds->place($request->vehicle, $request->range, $window->deadlineAt)
                : null;

            // 6. Reference, reserved under the counter lock.
            $reference = $this->references->next();

            $booking = Booking::query()->create([
                'reference' => $reference,
                'operator_id' => $request->vehicle->operator_id,
                'customer_id' => $resolution->customer->getKey(),
                'vehicle_id' => $request->vehicle->getKey(),
                'vehicle_class_id' => $request->vehicle->vehicle_class_id,
                'pickup_branch_id' => $request->pickupBranch->getKey(),
                'dropoff_branch_id' => $request->dropoffBranch->getKey(),

                'pickup_at' => $request->range->start,
                'dropoff_at' => $request->range->end,

                'status' => BookingStatus::PendingPayment,

                'payment_method_code' => $method->code,
                'payment_deadline_at' => $window->deadlineAt,
                'payment_reminder_at' => $window->reminderAt,
                'is_short_notice' => $window->isShortNotice,

                'hire_total' => $quote->hireTotal,
                'insurance_total' => $quote->insuranceTotal,
                'extras_total' => $quote->extrasTotal,
                'cross_border_total' => $quote->crossBorderTotal,
                'grand_total' => $quote->grandTotal,
                'currency' => $quote->currency,

                'pay_in_full' => $request->payInFull,
                'booking_deposit_amount' => $quote->bookingDepositAmount,
                'deposit_percentage' => $quote->depositPercentage,

                // Nothing has been paid yet. A staff member confirms receipt
                // later; until then the whole amount is outstanding, whichever
                // option the customer chose.
                'amount_paid' => Money::ZERO,
                'balance_due' => $quote->grandTotal,

                // Set explicitly rather than left to the column default.
                // create() does not read defaults back, so the returned model
                // would carry no value at all — and under Eloquent strict mode
                // reading it then throws, while in production it would simply
                // be null. The same shape of fault as the CustomerResolver
                // `needs_staff_review` bug in Phase 2.
                'payment_status' => BookingPaymentStatus::AwaitingPayment,

                'security_deposit_amount' => $quote->securityDepositAmount,
                'insurance_excess_amount' => $quote->insuranceExcessAmount,
                'cross_border_country' => $quote->crossBorderCountry,

                'terms_version' => $request->termsVersion,
                'terms_accepted_at' => $now,

                // The snapshot: what was agreed, immune to later changes.
                'vehicle_registration' => $request->vehicle->registration,
                'vehicle_make' => $request->vehicle->make,
                'vehicle_model' => $request->vehicle->model,
                'vehicle_class_name' => $request->vehicle->vehicleClass->name,
                'daily_rate_at_booking' => $quote->dailyRate,
                'chargeable_days' => $quote->chargeableDays,

                'confirmed_at' => null,
                'vehicle_released_at' => null,
                'completed_at' => null,
                'cancelled_at' => null,
                'cancellation_reason' => null,
            ]);

            if ($hold instanceof VehicleHold) {
                $hold->forceFill(['booking_id' => $booking->getKey()])->save();
            }

            // 7. The receipt the customer is now waiting to settle. Spec §14.3
            //    requires one for every offline booking, along with its own
            //    unique payment reference — including a short-notice booking,
            //    which places no hold but still leaves the customer with
            //    something to pay and a number to quote when they do.
            //
            //    Raised last because it derives its expected amount from the
            //    booking row, and inside the transaction so that a failure here
            //    takes the booking and the hold with it rather than leaving a
            //    vehicle claimed against a booking nobody was ever asked to pay
            //    for.
            $payment = $this->payments->raiseForBooking($booking, $method);

            return new BookingCreationResult(
                booking: $booking,
                quote: $quote,
                customerResolution: $resolution,
                paymentWindow: $window,
                payment: $payment,
                hold: $hold,
            );
        }, attempts: 3);
    }

    private function assertPickupIsInTheFuture(BookingRequest $request, CarbonImmutable $now): void
    {
        if ($request->range->start->lessThanOrEqualTo($now)) {
            throw BookingNotPossibleException::pickupInThePast($request->range->start, $now);
        }
    }

    /**
     * A vehicle cannot be collected from, or returned to, a branch belonging to
     * someone else's fleet. Trivial today with one operator; the check exists
     * because it stops being trivial the moment there are several.
     */
    private function assertBranchesBelongToTheVehiclesOperator(BookingRequest $request): void
    {
        foreach ([$request->pickupBranch, $request->dropoffBranch] as $branch) {
            if ($branch->operator_id !== $request->vehicle->operator_id) {
                throw BookingNotPossibleException::branchBelongsToAnotherOperator(
                    $branch,
                    $request->vehicle,
                );
            }
        }
    }
}
