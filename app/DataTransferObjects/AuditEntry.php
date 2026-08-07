<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Enums\AuditAction;
use App\Enums\PaymentMethodCode;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\User;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;

/**
 * One thing that happened, on its way to the audit log.
 *
 * Deliberately dumb: it holds values and converts nothing. AuditLogger does all
 * the normalising, so there is exactly one place where a status enum becomes a
 * string and an amount becomes a scaled decimal, rather than one per caller.
 *
 * WHY THERE IS NO `isAutomatic` PARAMETER
 *
 * Spec §12 requires every entry to record whether the action was manual or
 * automatic. That is not an independent fact — it is precisely whether a person
 * did it. Deriving it from `$actor` makes the two impossible to contradict; as
 * a separate flag, an entry could claim a staff member acted automatically, or
 * that a scheduled job was a person, and nothing would object.
 */
final class AuditEntry
{
    /**
     * @param  Model|null  $entity  The record acted upon, when it is not the
     *                              booking itself — a Payment, a VehicleHold.
     * @param  array<string, mixed>  $metadata  Anything worth keeping that has
     *                                          no column of its own.
     */
    public function __construct(
        public readonly AuditAction $action,
        public readonly ?User $actor = null,
        public readonly ?Booking $booking = null,
        public readonly ?Model $entity = null,
        public readonly BackedEnum|string|null $statusBefore = null,
        public readonly BackedEnum|string|null $statusAfter = null,
        public readonly string|int|null $amount = null,
        public readonly ?string $paymentReference = null,
        public readonly ?PaymentMethodCode $paymentMethod = null,
        public readonly ?bool $proofUploaded = null,
        public readonly ?Branch $branch = null,
        public readonly ?string $notes = null,
        public readonly array $metadata = [],
    ) {}

    /**
     * No actor means no person: a scheduled sweep, not somebody's decision.
     */
    public function isAutomatic(): bool
    {
        return $this->actor === null;
    }
}
