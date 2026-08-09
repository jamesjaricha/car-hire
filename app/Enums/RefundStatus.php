<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a refund is between being asked for and the money leaving.
 *
 * Spec §9.3 requires a refund record carrying "booking, amount, method, reason,
 * requesting staff, approving staff, status", and requires the requester and
 * approver to be different people. This enum is the status in that list, and
 * the two middle states exist because §9.3 puts two people in the path.
 *
 * WHY `disbursed` IS A STATUS AND ALSO A ROW IN ANOTHER TABLE
 *
 * The status is derived from the disbursement, never the other way round. A
 * refund is disbursed because a row exists in `refund_disbursements`, whose
 * unique key on `refund_id` is what makes double payout impossible. This column
 * is a readable summary of that fact for lists and filters; the row is the fact.
 * If they ever disagree, the row is right.
 */
enum RefundStatus: string
{
    /** Raised by staff, waiting for a second person. */
    case Requested = 'requested';

    /** Signed off. The money is still in the operator's hands. */
    case Approved = 'approved';

    /** Refused. The booking stays cancelled; no money moves. */
    case Rejected = 'rejected';

    /** The money has gone back, with a reference proving it. */
    case Disbursed = 'disbursed';

    /**
     * Whether this refund is finished with, one way or the other.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Disbursed, self::Rejected => true,
            self::Requested, self::Approved => false,
        };
    }

    public function canBeApproved(): bool
    {
        return $this === self::Requested;
    }

    /**
     * An approved refund can no longer be rejected.
     *
     * Reversing an approval is not a rejection — the customer has been told
     * they are getting their money. If an approval was wrong, that is a new
     * decision with its own trail, not a quiet edit of the old one.
     */
    public function canBeRejected(): bool
    {
        return $this === self::Requested;
    }

    public function canBeDisbursed(): bool
    {
        return $this === self::Approved;
    }

    /**
     * Whether the operator is still holding money that is owed to a customer.
     *
     * True from approval until disbursement. This is the window §9.3's second
     * person opens, and a refund sitting in it is somebody waiting for money —
     * the panel colours it accordingly.
     */
    public function awaitsPayout(): bool
    {
        return $this === self::Approved;
    }

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Awaiting approval',
            self::Approved => 'Approved, not yet paid out',
            self::Rejected => 'Rejected',
            self::Disbursed => 'Paid out',
        };
    }
}
