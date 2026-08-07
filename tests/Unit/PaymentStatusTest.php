<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\PaymentStatus;
use Tests\TestCase;

final class PaymentStatusTest extends TestCase
{
    /**
     * The decision this enum exists to get right: which receipts count as money
     * the operator is holding.
     */
    public function test_only_confirmed_and_refund_pending_money_counts_as_paid(): void
    {
        $this->assertTrue(PaymentStatus::Confirmed->countsTowardsAmountPaid());

        // Approved but not yet handed back. The operator still has the cash, so
        // showing the customer a balance they do not owe would be wrong — and
        // worse, the booking could be cancelled for non-payment while their
        // money sits in the till.
        $this->assertTrue(PaymentStatus::RefundPending->countsTowardsAmountPaid());

        $this->assertFalse(PaymentStatus::AwaitingPayment->countsTowardsAmountPaid());
        $this->assertFalse(PaymentStatus::ProofSubmitted->countsTowardsAmountPaid());
        $this->assertFalse(PaymentStatus::PaymentExpired->countsTowardsAmountPaid());
        $this->assertFalse(PaymentStatus::Refunded->countsTowardsAmountPaid());
    }

    /**
     * Spec §14.3 and the developer guideline §5: proof is not payment. An
     * uploaded screenshot must never move money, and doctored ones are common.
     */
    public function test_proof_submitted_is_not_payment(): void
    {
        $this->assertFalse(PaymentStatus::ProofSubmitted->countsTowardsAmountPaid());
        $this->assertTrue(PaymentStatus::ProofSubmitted->isOutstanding());
        $this->assertTrue(PaymentStatus::ProofSubmitted->isConfirmable());
    }

    public function test_only_an_open_receipt_may_be_confirmed(): void
    {
        $this->assertTrue(PaymentStatus::AwaitingPayment->isConfirmable());
        $this->assertTrue(PaymentStatus::ProofSubmitted->isConfirmable());

        // An expired receipt is not quietly confirmable after the fact; the
        // booking has already been cancelled for non-payment by then.
        $this->assertFalse(PaymentStatus::PaymentExpired->isConfirmable());

        // Already confirmed is refused by the unique key on
        // payment_confirmations, not by this flag — but the flag should not
        // invite the attempt either.
        $this->assertFalse(PaymentStatus::Confirmed->isConfirmable());

        $this->assertFalse(PaymentStatus::RefundPending->isConfirmable());
        $this->assertFalse(PaymentStatus::Refunded->isConfirmable());
    }

    public function test_outstanding_means_someone_still_owes_or_owes_a_decision(): void
    {
        $this->assertTrue(PaymentStatus::AwaitingPayment->isOutstanding());
        $this->assertTrue(PaymentStatus::ProofSubmitted->isOutstanding());

        $this->assertFalse(PaymentStatus::Confirmed->isOutstanding());
        $this->assertFalse(PaymentStatus::PaymentExpired->isOutstanding());
        $this->assertFalse(PaymentStatus::RefundPending->isOutstanding());
        $this->assertFalse(PaymentStatus::Refunded->isOutstanding());
    }

    public function test_the_stored_values_are_the_specification_strings(): void
    {
        $this->assertSame('awaiting_payment', PaymentStatus::AwaitingPayment->value);
        $this->assertSame('proof_submitted', PaymentStatus::ProofSubmitted->value);
        $this->assertSame('payment_expired', PaymentStatus::PaymentExpired->value);
        $this->assertSame('refund_pending', PaymentStatus::RefundPending->value);
        $this->assertSame('refunded', PaymentStatus::Refunded->value);

        // `confirmed` is ours. Spec §7.1 has no per-receipt confirmed state
        // because it describes partially_paid and paid_in_full instead, which
        // are properties of a booking rather than of one receipt. See
        // BookingPaymentStatus.
        $this->assertSame('confirmed', PaymentStatus::Confirmed->value);
    }

    public function test_every_case_has_a_label(): void
    {
        foreach (PaymentStatus::cases() as $status) {
            $this->assertNotSame('', $status->label());
        }
    }
}
