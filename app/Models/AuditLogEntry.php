<?php

declare(strict_types=1);

namespace App\Models;

use App\Exceptions\AuditLogImmutableException;
use Illuminate\Database\Eloquent\Model;

/**
 * One immutable record of a consequential action.
 *
 * Immutability is enforced twice, on purpose:
 *
 *  1. In the database, by BEFORE UPDATE and BEFORE DELETE triggers. That is the
 *     real guarantee — it holds regardless of what any code does.
 *  2. Here, by refusing the operation before it reaches the database. That gives
 *     a clear domain exception instead of a raw SQL error, and it fails loudly
 *     in development even if the triggers were somehow not installed.
 *
 * There is no `updated_at`, because a row that can be updated is not an audit
 * record.
 */
final class AuditLogEntry extends Model
{
    protected $table = 'audit_log';

    public const UPDATED_AT = null;

    protected $fillable = [
        'booking_id',
        'actor_user_id',
        'action',
        'entity',
        'entity_id',
        'status_before',
        'status_after',
        'amount',
        'payment_reference',
        'branch_id',
        'notes',
        'metadata',
        'is_automatic',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'metadata' => 'array',
            'is_automatic' => 'boolean',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(function (self $entry): void {
            throw AuditLogImmutableException::cannotUpdate($entry->getKey());
        });

        self::deleting(function (self $entry): void {
            throw AuditLogImmutableException::cannotDelete($entry->getKey());
        });
    }
}
