<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DataTransferObjects\RefundDisbursementResult;
use App\Models\Refund;
use App\Models\User;

/**
 * The third of spec §9.3's steps: the money actually leaving.
 *
 * Separate from `RefundRequestServiceContract` because §9.3 separates the
 * people.
 */
interface RefundDisbursementServiceContract
{
    /**
     * Pay an approved refund out, recording §9.3's proof.
     *
     * There is no amount parameter. The figure was computed and frozen when the
     * refund was raised, and a second person has already approved that figure.
     */
    public function disburse(
        User $actor,
        Refund $refund,
        string $disbursementReference,
        ?string $notes = null,
    ): RefundDisbursementResult;
}
