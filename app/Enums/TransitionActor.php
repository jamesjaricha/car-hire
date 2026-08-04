<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Who is permitted to make a booking state transition.
 *
 * Spec §7.3 gives an actor for every transition, and they are not
 * interchangeable: a customer may cancel their own unpaid booking, but only a
 * staff member may confirm a payment, and only the scheduled sweep may cancel
 * for non-payment.
 *
 * This is coarse — it says *what kind* of actor, not which permission. The
 * fine-grained permission matrix from spec §12 arrives with the admin panel and
 * layers on top of this.
 */
enum TransitionActor: string
{
    case Customer = 'customer';

    case Staff = 'staff';

    /** A scheduled job, not a person. Audited as automatic. */
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::Customer => 'Customer',
            self::Staff => 'Staff',
            self::System => 'System',
        };
    }
}
