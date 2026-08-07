<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Contracts\AuditLoggerContract;
use App\DataTransferObjects\AuditEntry;
use App\Models\AuditLogEntry;
use App\Support\Money;
use BackedEnum;

/**
 * Writes the audit trail. Nothing else may.
 *
 * The table has been append-only since Phase 1, enforced by MySQL BEFORE UPDATE
 * and BEFORE DELETE triggers. This is the first thing to write to it.
 *
 * Two conversions happen here rather than at the call sites, so that they
 * happen the same way every time:
 *
 *  - Statuses arrive as enums and are stored as their backing strings. An
 *    entry recording `App\Enums\BookingStatus::Confirmed` as an object would be
 *    unreadable in SQL, and a caller passing the enum name rather than its
 *    value would be a different string again.
 *  - Amounts are normalised through Money. A figure that arrives as '300' is
 *    stored as '300.00', so the audit trail and the payment it describes can be
 *    compared without either being re-scaled first.
 */
final class AuditLogger implements AuditLoggerContract
{
    public function record(AuditEntry $entry): AuditLogEntry
    {
        return AuditLogEntry::query()->create([
            'booking_id' => $entry->booking?->getKey(),
            'actor_user_id' => $entry->actor?->getKey(),

            'action' => $entry->action->value,

            // The record acted upon, when that is something other than the
            // booking. Stored as a short class name rather than a FQCN: the
            // namespace tells a reader nothing and breaks the moment a class
            // moves.
            'entity' => $entry->entity === null ? null : class_basename($entry->entity),
            'entity_id' => $entry->entity?->getKey(),

            'status_before' => $this->stringify($entry->statusBefore),
            'status_after' => $this->stringify($entry->statusAfter),

            'amount' => $entry->amount === null ? null : Money::of($entry->amount),
            'payment_reference' => $entry->paymentReference,
            'payment_method_code' => $entry->paymentMethod?->value,
            'proof_uploaded' => $entry->proofUploaded,

            // An explicit branch wins; otherwise the acting staff member's own.
            // Spec §12 wants the branch the action happened at, which is the
            // counter the person was standing at — not the booking's pickup
            // branch, which may be somewhere else entirely.
            'branch_id' => $entry->branch?->getKey() ?? $entry->actor?->branch_id,

            'notes' => $entry->notes,

            // Stored as null rather than an empty object, so that "nothing
            // extra was recorded" reads the same in every row.
            'metadata' => $entry->metadata === [] ? null : $entry->metadata,

            'is_automatic' => $entry->isAutomatic(),
        ]);
    }

    private function stringify(BackedEnum|string|null $value): ?string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return $value;
    }
}
