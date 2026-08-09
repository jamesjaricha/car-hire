<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why money is going back. Spec §9.1, §9.2 and §11.
 *
 * The reason is not a note — it is the input to the calculation. Each case
 * answers the same two questions, and `RefundCalculator` asks nothing else:
 * is the booking deposit withheld, and is the admin fee deducted. Keeping those
 * answers here rather than in the calculator means the specification's four
 * rules can be read side by side with §9 in one file.
 *
 * WHY THERE IS NO 'other' OR 'goodwill' CASE
 *
 * The refund amount is computed and locked; staff cannot type a figure. A free
 * reason would have no rule to compute from, so it would need a typed amount,
 * and one editable-amount path is all it takes for the locked ones to become
 * advisory. If the operator later needs discretionary refunds, that is a
 * deliberate feature with its own permission and its own approval trail, not a
 * fifth case bolted onto this enum.
 */
enum RefundReason: string
{
    /**
     * The customer asked to cancel. Spec §9.1.
     *
     * The only timing-sensitive case: more than 24 hours before pickup the
     * booking deposit comes back, inside 24 hours it does not.
     */
    case CustomerCancellation = 'customer_cancellation';

    /** They never arrived. Spec §9.1 treats this as a within-24-hours cancellation. */
    case NoShow = 'no_show';

    /** Documents rejected at the counter. Spec §9.2 — fee only, whatever the timing. */
    case FailedKyc = 'failed_kyc';

    /**
     * The authorisation letter, TIP paperwork or insurance extension could not
     * be obtained. Spec §11: full refund, no admin fee, because the failure is
     * operational rather than anything the customer did.
     */
    case CrossBorderPaperworkFailed = 'cross_border_paperwork_failed';

    /**
     * Whether the flat admin fee comes off this refund.
     *
     * False only for cross-border paperwork failure, and that is spec §11
     * speaking rather than a kindness: the operator could not deliver what was
     * sold, so charging the customer to process the consequence would be
     * charging them for the operator's own failure.
     */
    public function deductsAdminFee(): bool
    {
        return $this !== self::CrossBorderPaperworkFailed;
    }

    /**
     * Whether the booking deposit is withheld. Spec §9.1.
     *
     * The window argument only decides the customer-cancellation case. A no-show
     * always retains it — §9.1 says a no-show is treated as a within-24-hours
     * cancellation, and by definition it is discovered at or after pickup, so
     * the window question never arises for it.
     *
     * Failed KYC does not retain it. §9.2 says "amount paid minus admin fee,
     * regardless of timing", and reading the deposit rule into that would take
     * a customer's deposit for failing a check the operator applied at the desk.
     */
    public function retainsBookingDeposit(bool $insideNoticeWindow): bool
    {
        return match ($this) {
            self::CustomerCancellation => $insideNoticeWindow,
            self::NoShow => true,
            self::FailedKyc, self::CrossBorderPaperworkFailed => false,
        };
    }

    /**
     * Whether the outcome depends on how close to pickup the request is made.
     *
     * Used by the screens to explain a figure. Only the customer-cancellation
     * case changes with the clock, and telling somebody their refund was reduced
     * "because it is inside 24 hours" when the real reason was a failed KYC
     * check is worse than saying nothing.
     */
    public function isTimingSensitive(): bool
    {
        return $this === self::CustomerCancellation;
    }

    /**
     * The booking status this reason cancels to. Spec §7.3.
     *
     * Cross-border paperwork failure maps to `cancelled_by_customer` because
     * that is the row §7.3 gives it — the specification has no separate
     * operational-cancellation state, and inventing one here would put a value
     * in the column that `BookingStateMachine` has never heard of.
     *
     * Nothing validates the pairing here on purpose. Whether a booking may
     * actually make this move — a no-show is only reachable from `confirmed`,
     * a failed KYC likewise — is the state machine's question, and asking it
     * twice is how two answers start to disagree.
     */
    public function cancelsBookingTo(): BookingStatus
    {
        return match ($this) {
            self::CustomerCancellation, self::CrossBorderPaperworkFailed => BookingStatus::CancelledByCustomer,
            self::NoShow => BookingStatus::NoShow,
            self::FailedKyc => BookingStatus::CancelledFailedKyc,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::CustomerCancellation => 'Customer cancelled',
            self::NoShow => 'No show',
            self::FailedKyc => 'KYC failed at the counter',
            self::CrossBorderPaperworkFailed => 'Cross-border paperwork could not be obtained',
        };
    }

    /**
     * The rule being applied, in the words staff would use to explain it.
     */
    public function description(): string
    {
        return match ($this) {
            self::CustomerCancellation => 'More than 24 hours before pickup, the amount paid comes back less the admin fee. Inside 24 hours, the booking deposit is also non-refundable. Spec §9.1.',
            self::NoShow => 'Treated as a cancellation inside 24 hours: the booking deposit is non-refundable and the admin fee is deducted. Spec §9.1.',
            self::FailedKyc => 'The amount paid comes back less the admin fee, whatever the timing. Spec §9.2.',
            self::CrossBorderPaperworkFailed => 'Refunded in full with no admin fee — the failure is operational, not the customer\'s. Spec §11.',
        };
    }
}
