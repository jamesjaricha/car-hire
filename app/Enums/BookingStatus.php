<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a booking is in its life.
 *
 * These are the states from spec §7.2, unchanged and complete. Booking state and
 * payment state are separate entities and must not be merged — a booking can be
 * `Confirmed` while its payment is only `partially_paid`, which is the normal
 * case for a 50% deposit.
 *
 * `Basket` is listed here even though no row is ever stored in it. The basket
 * lives in the guest's session and a booking row is only created when checkout
 * is submitted. It is kept in the enum so the transition map can be read
 * side by side with the specification without a gap where §7.3 has an entry.
 */
enum BookingStatus: string
{
    /** Guest session, no contact details. Never persisted — see above. */
    case Basket = 'basket';

    /** Submitted, vehicle held, awaiting funds. */
    case PendingPayment = 'pending_payment';

    /** Payment confirmed by a staff member. Not by proof upload. */
    case Confirmed = 'confirmed';

    /** Paid, but cross-border paperwork is still being obtained. Spec §11. */
    case AwaitingCrossBorder = 'awaiting_cross_border';

    /** KYC verified, balance settled, security deposit taken, keys handed over. */
    case VehicleReleased = 'vehicle_released';

    /** Vehicle returned and inspected, security deposit settled. */
    case Completed = 'completed';

    case CancelledByCustomer = 'cancelled_by_customer';

    /** Payment deadline elapsed. Set by the scheduled sweep. */
    case CancelledNonPayment = 'cancelled_non_payment';

    /** Documents rejected at the counter. Refund less the admin fee. */
    case CancelledFailedKyc = 'cancelled_failed_kyc';

    case NoShow = 'no_show';

    /**
     * Whether the booking is finished, one way or another.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed,
            self::CancelledByCustomer,
            self::CancelledNonPayment,
            self::CancelledFailedKyc,
            self::NoShow => true,
            default => false,
        };
    }

    /**
     * Whether a booking in this state should still be claiming its vehicle.
     *
     * Used by the expiry sweep and by staff reassignment: once a booking stops
     * claiming, its hold must be released or the vehicle silently leaves the
     * bookable fleet.
     */
    public function claimsVehicle(): bool
    {
        return match ($this) {
            self::PendingPayment,
            self::Confirmed,
            self::AwaitingCrossBorder,
            self::VehicleReleased => true,
            default => false,
        };
    }

    public function isCancellation(): bool
    {
        return match ($this) {
            self::CancelledByCustomer,
            self::CancelledNonPayment,
            self::CancelledFailedKyc => true,
            default => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Basket => 'Basket',
            self::PendingPayment => 'Awaiting payment',
            self::Confirmed => 'Confirmed',
            self::AwaitingCrossBorder => 'Awaiting cross-border paperwork',
            self::VehicleReleased => 'Vehicle released',
            self::Completed => 'Completed',
            self::CancelledByCustomer => 'Cancelled by customer',
            self::CancelledNonPayment => 'Cancelled — not paid',
            self::CancelledFailedKyc => 'Cancelled — KYC failed',
            self::NoShow => 'No show',
        };
    }
}
