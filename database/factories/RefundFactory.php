<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PaymentMethodCode;
use App\Enums\RefundReason;
use App\Enums\RefundStatus;
use App\Models\Booking;
use App\Models\Refund;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Refund>
 *
 * The default is a customer cancellation made in good time: K2,310 held, no
 * deposit forfeited, a K150 fee, K2,160 going back. Those figures agree with
 * BookingFactory's defaults so a refund and the booking it belongs to tell the
 * same story without a test having to set both.
 *
 * NOTE ON `approvedBy()`: the table carries a CHECK constraint refusing an
 * approver who is also the requester (spec §9.3). A state that sets one without
 * the other will hit it, which is the constraint working — pass different users.
 */
final class RefundFactory extends Factory
{
    protected $model = Refund::class;

    public function definition(): array
    {
        return [
            'booking_id' => Booking::factory(),
            'operator_id' => null,

            'reason' => RefundReason::CustomerCancellation,
            'status' => RefundStatus::Requested,
            'method' => PaymentMethodCode::Cash,

            'amount_paid_at_request' => '2310.00',
            'booking_deposit_retained' => '0.00',
            'admin_fee_configured' => '150.00',
            'admin_fee_deducted' => '150.00',
            'amount' => '2160.00',
            'admin_fee_was_placeholder' => false,
            'currency' => 'ZMW',

            'requested_by_user_id' => User::factory(),
            'requested_at' => CarbonImmutable::now(),

            'approved_by_user_id' => null,
            'approved_at' => null,
            'rejected_by_user_id' => null,
            'rejected_at' => null,
            'rejection_reason' => null,
            'notes' => null,
        ];
    }

    public function forBooking(Booking $booking): self
    {
        return $this->state(fn (): array => [
            'booking_id' => $booking->getKey(),
            'operator_id' => $booking->operator_id,
            'currency' => $booking->currency,
        ]);
    }

    public function requestedBy(User $user): self
    {
        return $this->state(fn (): array => [
            'requested_by_user_id' => $user->getKey(),
        ]);
    }

    /**
     * Approved, and therefore ready to be paid out.
     */
    public function approvedBy(User $user): self
    {
        return $this->state(fn (): array => [
            'status' => RefundStatus::Approved,
            'approved_by_user_id' => $user->getKey(),
            'approved_at' => CarbonImmutable::now(),
        ]);
    }

    public function rejectedBy(User $user, string $reason = 'Not eligible under the terms.'): self
    {
        return $this->state(fn (): array => [
            'status' => RefundStatus::Rejected,
            'rejected_by_user_id' => $user->getKey(),
            'rejected_at' => CarbonImmutable::now(),
            'rejection_reason' => $reason,
        ]);
    }

    /**
     * Already paid out.
     *
     * Sets the summary column only. A refund that is genuinely disbursed also
     * has a row in `refund_disbursements` — create one with
     * RefundDisbursementFactory when the test cares about the record rather than
     * the status.
     */
    public function disbursed(): self
    {
        return $this->state(fn (): array => [
            'status' => RefundStatus::Disbursed,
        ]);
    }

    public function reason(RefundReason $reason): self
    {
        return $this->state(fn (): array => [
            'reason' => $reason,
        ]);
    }

    /**
     * Computed while the §15.1 admin fee was still an undecided placeholder.
     */
    public function withPlaceholderFee(): self
    {
        return $this->state(fn (): array => [
            'admin_fee_configured' => '0.00',
            'admin_fee_deducted' => '0.00',
            'admin_fee_was_placeholder' => true,
            'amount' => '2310.00',
        ]);
    }
}
