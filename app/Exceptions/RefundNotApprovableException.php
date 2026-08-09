<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\RefundStatus;
use DomainException;

/**
 * A refund cannot be approved or rejected as asked.
 */
final class RefundNotApprovableException extends DomainException
{
    /**
     * SPEC §9.3'S TWO-PERSON RULE.
     *
     * "Require a separate role to approve refunds from the one that requests
     * them." This is a fraud control, not a workflow nicety: one person who can
     * both raise and approve a refund can move money out of the business alone,
     * and the audit trail would show it as a properly authorised payment.
     *
     * Enforced here AND by a CHECK constraint on the table. See the migration.
     */
    public static function sameUserRequestedIt(int $refundId): self
    {
        return new self(sprintf(
            'Refund #%d was raised by you, and spec §9.3 requires a different person to approve it. '
            .'This is a fraud control: whoever asks for money to leave the business must not also be '
            .'the one who authorises it.',
            $refundId,
        ));
    }

    public static function statusForbidsIt(int $refundId, RefundStatus $status): self
    {
        return new self(sprintf(
            'Refund #%d is %s, so it can no longer be approved or rejected.',
            $refundId,
            lcfirst($status->label()),
        ));
    }

    /**
     * A rejection is a decision somebody will have to defend to a customer who
     * expected their money. It carries a reason or it does not happen.
     */
    public static function rejectionNeedsAReason(int $refundId): self
    {
        return new self(sprintf(
            'Refund #%d cannot be rejected without a reason. The customer asked for money back and is '
            .'being told no; the record must say why.',
            $refundId,
        ));
    }
}
