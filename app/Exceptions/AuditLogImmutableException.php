<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when something attempts to alter or remove an audit entry.
 *
 * Reaching this exception is always a bug. The audit trail is append-only by
 * specification, so there is no legitimate caller — correcting a mistaken entry
 * means appending a correcting entry, never editing the original.
 */
final class AuditLogImmutableException extends RuntimeException
{
    public static function cannotUpdate(mixed $id): self
    {
        return new self(
            "Audit entry [{$id}] cannot be updated. The audit log is append-only; "
            .'record a correcting entry instead.'
        );
    }

    public static function cannotDelete(mixed $id): self
    {
        return new self(
            "Audit entry [{$id}] cannot be deleted. The audit log is append-only."
        );
    }
}
