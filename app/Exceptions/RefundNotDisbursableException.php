<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\RefundStatus;
use App\Models\RefundDisbursement;
use DomainException;

/**
 * Money cannot be paid out against this refund.
 *
 * `alreadyDisbursed()` is the one that matters. Spec §9.3: "Never allow the same
 * refund to be disbursed twice."
 */
final class RefundNotDisbursableException extends DomainException
{
    /**
     * Raised from two places on purpose, and the caller cannot tell which.
     *
     * The service checks under a lock and refuses politely, naming whoever got
     * there first. If a racer slips between that check and the insert, the
     * unique key on `refund_disbursements.refund_id` refuses it instead, and
     * that path is translated into this same exception. Staff should not be able
     * to tell an application check from a constraint violation — both mean the
     * money has already gone.
     */
    public static function alreadyDisbursed(int $refundId, ?RefundDisbursement $existing = null): self
    {
        if ($existing === null) {
            return new self(sprintf(
                'Refund #%d has already been paid out. Nothing further has been disbursed.',
                $refundId,
            ));
        }

        return new self(sprintf(
            'Refund #%d was already paid out on %s, reference [%s]. Nothing further has been disbursed.',
            $refundId,
            $existing->disbursed_at->toDateTimeString(),
            $existing->disbursement_reference,
        ));
    }

    public static function statusForbidsIt(int $refundId, RefundStatus $status): self
    {
        return new self(sprintf(
            'Refund #%d is %s. Only an approved refund can be paid out — spec §9.3 puts a second '
            .'person between the request and the money.',
            $refundId,
            lcfirst($status->label()),
        ));
    }

    /**
     * Spec §9.3 requires proof of disbursement: a signed cash receipt number, a
     * transfer reference, a MoMo transaction ID. If there is nothing to type,
     * the money has not actually left.
     */
    public static function referenceRequired(int $refundId): self
    {
        return new self(sprintf(
            'Refund #%d cannot be paid out without a disbursement reference. Spec §9.3 requires proof: '
            .'the cash receipt number, the transfer reference, or the mobile money transaction ID.',
            $refundId,
        ));
    }
}
