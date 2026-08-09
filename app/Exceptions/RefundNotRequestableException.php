<?php

declare(strict_types=1);

namespace App\Exceptions;

use DomainException;

/**
 * A refund cannot be raised as asked.
 *
 * None of these are about authority — that is `StaffPermissionDeniedException`.
 * These are about the request not making sense against the booking as it stands.
 */
final class RefundNotRequestableException extends DomainException
{
    /**
     * The §9 calculation came to nothing.
     *
     * Not an error in itself: a customer who paid only their deposit and
     * cancelled two hours before pickup forfeits exactly that, and is owed
     * nothing. But a refund row for zero would sit in the approval queue asking
     * two people to sign off a payment that will never be made, and then wait
     * forever to be disbursed — §9.3 requires a disbursement reference, and
     * there is no reference for money that did not move.
     *
     * The cancellation still happens and still records the computed figures in
     * its audit entry. Nothing is lost except a row nobody could action.
     */
    public static function nothingToRefund(string $reference, string $amountPaid): self
    {
        return new self(sprintf(
            'Booking [%s] has no refundable amount: the %s held is entirely accounted for by the '
            .'forfeited booking deposit and the admin fee. The cancellation is recorded with the '
            .'calculation; no refund has been raised because there is nothing to pay out.',
            $reference,
            $amountPaid,
        ));
    }

    /**
     * One live refund per booking at a time.
     *
     * Two open refunds against one booking is how the same money gets paid back
     * twice through two approvals — the disbursement key guards a single refund
     * being paid twice, but it cannot see that a second refund covers the same
     * receipts. This is that guard.
     */
    public static function alreadyOpen(string $reference): self
    {
        return new self(sprintf(
            'Booking [%s] already has a refund awaiting approval or payout. Resolve that one before '
            .'raising another — two open refunds against one booking can pay the same money back twice.',
            $reference,
        ));
    }
}
