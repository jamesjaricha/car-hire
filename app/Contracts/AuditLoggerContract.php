<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DataTransferObjects\AuditEntry;
use App\Models\AuditLogEntry;

/**
 * The only sanctioned writer to `audit_log`.
 *
 * Same discipline as VehicleHoldServiceContract: a single writer, so that what
 * an entry contains is decided in one place. Spec §12 lists what every entry
 * must record, and a second writer is how half of those fields come to be
 * populated only sometimes.
 */
interface AuditLoggerContract
{
    /**
     * Append an entry. Never updates, never deletes — the table forbids both,
     * and so does the model.
     *
     * Call this INSIDE the transaction that performs the action being recorded.
     * An audit entry that survives a rolled-back action is a record of
     * something that did not happen.
     */
    public function record(AuditEntry $entry): AuditLogEntry;
}
