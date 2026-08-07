<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\StaffPermission;
use DomainException;

/**
 * A staff member attempted something spec §12 does not grant them.
 *
 * The message names the action in the words a person would use, not the
 * internal permission string. `config/permission.php` keeps permission and role
 * names out of the package's own exceptions on the grounds that they describe
 * the shape of the access model; the same reasoning applies here, and "you
 * cannot confirm a bank transfer" is more use to a counter clerk than
 * "payments.confirm-bank-transfer" anyway.
 */
final class StaffPermissionDeniedException extends DomainException
{
    public static function missing(StaffPermission $permission): self
    {
        return new self(
            'You do not have permission to '.lcfirst($permission->label()).'.'
        );
    }

    /**
     * The §15.12 case: the clerk holds the permission, but the operator has not
     * switched cash confirmation on for counter staff.
     */
    public static function counterClerkCashConfirmationDisabled(): self
    {
        return new self(
            'Counter staff are not currently permitted to confirm cash payments. '
            .'A branch manager must confirm this one.'
        );
    }
}
