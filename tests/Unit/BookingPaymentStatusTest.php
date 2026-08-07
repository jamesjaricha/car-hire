<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\BookingPaymentStatus;
use Tests\TestCase;

final class BookingPaymentStatusTest extends TestCase
{
    /**
     * Spec §7.1, transcribed by hand. These strings are the specification's,
     * and this enum is the one that carries them verbatim.
     */
    public function test_it_is_the_specification_list(): void
    {
        $this->assertSame([
            'awaiting_payment',
            'proof_submitted',
            'partially_paid',
            'paid_in_full',
            'payment_expired',
            'refund_pending',
            'refunded',
        ], array_map(
            static fn (BookingPaymentStatus $case): string => $case->value,
            BookingPaymentStatus::cases(),
        ));
    }

    /**
     * The distinction spec §5 and §14.3 turn on: a 50% deposit is enough to
     * confirm a booking and never enough to release a vehicle.
     */
    public function test_a_partial_payment_has_funds_but_is_not_settled(): void
    {
        $this->assertTrue(BookingPaymentStatus::PartiallyPaid->hasConfirmedFunds());
        $this->assertFalse(BookingPaymentStatus::PartiallyPaid->isSettled());

        $this->assertTrue(BookingPaymentStatus::PaidInFull->hasConfirmedFunds());
        $this->assertTrue(BookingPaymentStatus::PaidInFull->isSettled());
    }

    public function test_nothing_else_is_settled(): void
    {
        foreach (BookingPaymentStatus::cases() as $status) {
            if ($status === BookingPaymentStatus::PaidInFull) {
                continue;
            }

            $this->assertFalse(
                $status->isSettled(),
                "{$status->value} must not count as settled.",
            );
        }
    }

    public function test_uploaded_proof_is_not_confirmed_funds(): void
    {
        $this->assertFalse(BookingPaymentStatus::ProofSubmitted->hasConfirmedFunds());
        $this->assertFalse(BookingPaymentStatus::AwaitingPayment->hasConfirmedFunds());
        $this->assertFalse(BookingPaymentStatus::PaymentExpired->hasConfirmedFunds());
    }

    /**
     * Consistent with PaymentStatus: money approved for refund but not yet
     * handed over is still money the operator holds.
     */
    public function test_a_pending_refund_still_counts_as_funds_held(): void
    {
        $this->assertTrue(BookingPaymentStatus::RefundPending->hasConfirmedFunds());
        $this->assertFalse(BookingPaymentStatus::Refunded->hasConfirmedFunds());
    }

    public function test_every_case_has_a_label(): void
    {
        foreach (BookingPaymentStatus::cases() as $status) {
            $this->assertNotSame('', $status->label());
        }
    }
}
