<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The audited actions of specification §12.
 *
 * §12 names them: "every payment confirmation, refund request and approval,
 * security deposit collection and return, KYC verification and rejection,
 * vehicle reassignment, deadline extension, short-notice override, cross-border
 * confirmation, payment method enable/disable."
 *
 * All of them are declared here, including the ones no code writes yet. The
 * alternative — adding cases phase by phase — is how an audit trail ends up
 * with `payment.confirmed`, `payment_confirmed` and `confirm_payment` in the
 * same table, at which point querying it honestly becomes guesswork. The
 * vocabulary is cheap; agreeing on it after the fact is not.
 *
 * Values are dot-separated and match the permission names of §12 where the two
 * describe the same act, so "who may do this" and "who did this" read alike.
 */
enum AuditAction: string
{
    // --- Payments ------------------------------------------------------------

    /** A receipt was raised — by checkout, or keyed in by staff. */
    case PaymentRecorded = 'payment.recorded';

    /** Staff verified that money actually arrived. Spec §7.3. */
    case PaymentConfirmed = 'payment.confirmed';

    /** An unmatched receipt was attributed to a booking. */
    case PaymentMatched = 'payment.matched';

    /** The customer uploaded proof. Not a confirmation. Spec §14.3. */
    case PaymentProofSubmitted = 'payment.proof-submitted';

    /** The deadline passed with the payment unconfirmed. Spec §8.4. */
    case PaymentExpired = 'payment.expired';

    /** Spec §12 payments.edit-manual-payment. */
    case PaymentEdited = 'payment.edited';

    /** Spec §12 payments.extend-deadline. */
    case PaymentDeadlineExtended = 'payment.deadline-extended';

    // --- Bookings ------------------------------------------------------------

    case BookingCreated = 'booking.created';
    case BookingConfirmed = 'booking.confirmed';

    /** The expiry sweep cancelled an unpaid booking. Spec §8.4. */
    case BookingCancelledNonPayment = 'booking.cancelled-non-payment';

    case BookingCancelled = 'booking.cancelled';

    /** Spec §8.3. Always logged with reason, old vehicle and new vehicle. */
    case BookingVehicleReassigned = 'booking.vehicle-reassigned';

    /** Spec §8.2. Staff overrode the under-four-hours rule. */
    case BookingShortNoticeOverridden = 'booking.short-notice-overridden';

    // --- Counter operations --------------------------------------------------

    case KycVerified = 'kyc.verified';
    case KycRejected = 'kyc.rejected';

    case SecurityDepositCollected = 'security-deposit.collected';
    case SecurityDepositReturned = 'security-deposit.returned';

    case VehicleReleased = 'booking.vehicle-released';
    case VehicleReturned = 'booking.vehicle-returned';

    // --- Refunds -------------------------------------------------------------

    case RefundRequested = 'refund.requested';
    case RefundApproved = 'refund.approved';

    /**
     * NOT NAMED IN §12's list, added deliberately.
     *
     * §12 enumerates "refund request and approval". A rejection is neither, and
     * yet §9.3 requires every refund state change to be audited — so without
     * this case the one refund outcome a customer is most likely to dispute
     * would be the only one leaving no trace of who decided it.
     */
    case RefundRejected = 'refund.rejected';

    case RefundDisbursed = 'refund.disbursed';

    // --- Administration ------------------------------------------------------

    case CrossBorderConfirmed = 'cross-border.confirmed';

    case PaymentMethodEnabled = 'payment-method.enabled';
    case PaymentMethodDisabled = 'payment-method.disabled';

    public function label(): string
    {
        return match ($this) {
            self::PaymentRecorded => 'Payment recorded',
            self::PaymentConfirmed => 'Payment confirmed',
            self::PaymentMatched => 'Payment matched to a booking',
            self::PaymentProofSubmitted => 'Proof of payment submitted',
            self::PaymentExpired => 'Payment expired',
            self::PaymentEdited => 'Payment edited',
            self::PaymentDeadlineExtended => 'Payment deadline extended',
            self::BookingCreated => 'Booking created',
            self::BookingConfirmed => 'Booking confirmed',
            self::BookingCancelledNonPayment => 'Booking cancelled for non-payment',
            self::BookingCancelled => 'Booking cancelled',
            self::BookingVehicleReassigned => 'Vehicle reassigned',
            self::BookingShortNoticeOverridden => 'Short-notice rule overridden',
            self::KycVerified => 'KYC verified',
            self::KycRejected => 'KYC rejected',
            self::SecurityDepositCollected => 'Security deposit collected',
            self::SecurityDepositReturned => 'Security deposit returned',
            self::VehicleReleased => 'Vehicle released',
            self::VehicleReturned => 'Vehicle returned',
            self::RefundRequested => 'Refund requested',
            self::RefundApproved => 'Refund approved',
            self::RefundRejected => 'Refund rejected',
            self::RefundDisbursed => 'Refund disbursed',
            self::CrossBorderConfirmed => 'Cross-border paperwork confirmed',
            self::PaymentMethodEnabled => 'Payment method enabled',
            self::PaymentMethodDisabled => 'Payment method disabled',
        };
    }
}
