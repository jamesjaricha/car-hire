<?php

declare(strict_types=1);

namespace App\Services\Refunds;

use App\Contracts\RefundCalculatorContract;
use App\Contracts\SettingsRepositoryContract;
use App\DataTransferObjects\RefundQuote;
use App\Enums\RefundReason;
use App\Enums\SettingKey;
use App\Models\Booking;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Spec §9, as a function.
 *
 * WHY THIS IS SEPARATE FROM THE SERVICES THAT USE IT
 *
 * It writes nothing, locks nothing and decides nothing about permissions. That
 * makes it the one part of the refund flow that can be tested exhaustively —
 * every reason against every timing against every awkward amount — without a
 * booking being cancelled or a transaction being opened. The rules in §9 are
 * where a mistake costs real money, so they are the part that gets the matrix.
 *
 * It is also what lets a screen show the customer's figure before anybody
 * commits to it. Staff should be able to see what a refund would come to
 * without raising one.
 *
 * THE ORDER OF DEDUCTIONS MATTERS
 *
 * Deposit first, then fee on what is left. Reversed, a customer who paid only
 * their deposit and cancelled late would have the fee taken from money that was
 * already forfeit, and the arithmetic would come out the same by luck rather
 * than by rule. Doing it in §9.1's own order — "the booking deposit is
 * non-refundable; any amount paid above the deposit is refunded minus the flat
 * admin fee" — keeps the clamping honest.
 *
 * EVERYTHING IS CLAMPED AT ZERO
 *
 * Neither deduction may create a debt. Spec §9 describes money being withheld
 * from a sum already held; it never describes billing somebody for cancelling.
 * A customer who paid K100 against a K1,155 deposit forfeits their K100 and is
 * owed nothing — they are not sent an invoice for K1,055.
 */
final class RefundCalculator implements RefundCalculatorContract
{
    /** Spec §9.1, used only if the setting has somehow gone missing. */
    private const FALLBACK_NOTICE_HOURS = 24;

    public function __construct(
        private readonly SettingsRepositoryContract $settings,
    ) {}

    public function quote(Booking $booking, RefundReason $reason, ?CarbonImmutable $asOf = null): RefundQuote
    {
        $asOf ??= CarbonImmutable::now();

        // What the operator is actually holding. Not the grand total, and not
        // what the customer was invoiced — a refund can only ever return money
        // that arrived.
        $amountPaid = Money::of($booking->amount_paid);

        $noticeHours = $this->settings->integer(SettingKey::CancellationNoticeHours, self::FALLBACK_NOTICE_HOURS)
            ?? self::FALLBACK_NOTICE_HOURS;

        $insideNoticeWindow = $this->isInsideNoticeWindow($booking, $asOf, $noticeHours);

        // Spec §9.1, first deduction.
        $depositRetained = $reason->retainsBookingDeposit($insideNoticeWindow)
            ? $this->atMost(Money::of($booking->booking_deposit_amount), $amountPaid)
            : Money::ZERO;

        $remaining = Money::subtract($amountPaid, $depositRetained);

        // Spec §9.1 and §9.2, second deduction — and §11, which skips it.
        $configuredFee = Money::of(
            $this->settings->decimal(SettingKey::AdminFeeAmount, Money::ZERO) ?? Money::ZERO
        );

        $feeDeducted = $reason->deductsAdminFee()
            ? $this->atMost($configuredFee, $remaining)
            : Money::ZERO;

        return new RefundQuote(
            reason: $reason,
            amountPaid: $amountPaid,
            bookingDepositRetained: $depositRetained,
            adminFeeConfigured: $reason->deductsAdminFee() ? $configuredFee : Money::ZERO,
            adminFeeDeducted: $feeDeducted,
            amount: Money::subtract($remaining, $feeDeducted),

            // Only meaningful where the fee is actually applied. A cross-border
            // refund deducts nothing, so calling it "computed with a
            // placeholder" would put a warning on a figure the placeholder had
            // no part in — and a warning that appears where it does not belong
            // is how people learn to ignore warnings.
            adminFeeIsPlaceholder: $reason->deductsAdminFee()
                && $this->settings->isPlaceholder(SettingKey::AdminFeeAmount),

            insideNoticeWindow: $insideNoticeWindow,
            noticeWindowHours: $noticeHours,
        );
    }

    /**
     * Spec §9.1's boundary.
     *
     * "More than 24 hours before pickup" keeps the deposit; "within 24 hours"
     * forfeits it. So the instant exactly 24 hours before pickup is already
     * inside the window — it is not *more than* 24 hours out. The comparison is
     * inclusive for that reason, and there is a test pinning it, because this
     * is the sort of boundary that gets flipped during a tidy-up.
     *
     * A booking whose pickup has already passed is inside the window by the
     * same arithmetic, which is what a no-show needs.
     */
    private function isInsideNoticeWindow(Booking $booking, CarbonImmutable $asOf, int $noticeHours): bool
    {
        return $asOf->greaterThanOrEqualTo($booking->pickup_at->subHours($noticeHours));
    }

    /**
     * The smaller of the two. Both arrive normalised; the result is too.
     */
    private function atMost(string $amount, string $ceiling): string
    {
        return Money::compare($amount, $ceiling) > 0 ? $ceiling : $amount;
    }
}
