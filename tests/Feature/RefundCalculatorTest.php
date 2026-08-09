<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\RefundCalculatorContract;
use App\Contracts\SettingsRepositoryContract;
use App\Enums\RefundReason;
use App\Enums\SettingKey;
use App\Models\Booking;
use Carbon\CarbonImmutable;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spec §9's arithmetic, every rule against every timing.
 *
 * This is the exhaustive one. The calculator is pure, so there is no excuse for
 * testing it thinly — and it is the piece where an error is a customer being
 * given the wrong amount of real money, discovered by them rather than by us.
 *
 * The bookings here are built in memory rather than through the factory. The
 * calculator reads three columns, and constructing an operator, a class, a
 * vehicle, two branches and a customer to exercise them would make the matrix
 * slow enough that somebody would eventually thin it out.
 */
final class RefundCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $now;

    private RefundCalculatorContract $calculator;

    private SettingsRepositoryContract $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::parse('2026-09-06T09:00:00Z');
        $this->travelTo($this->now);

        $this->seed(SettingsSeeder::class);

        $this->settings = app(SettingsRepositoryContract::class);
        $this->settings->flush();

        $this->calculator = app(RefundCalculatorContract::class);

        // A decided fee for every test but the placeholder ones, so the figures
        // below exercise the arithmetic rather than the §15.1 zero.
        $this->settings->set(SettingKey::AdminFeeAmount, '150.00');
    }

    // --- §9.1 Customer cancellation ----------------------------------------

    public function test_cancelling_more_than_24_hours_out_refunds_everything_less_the_fee(): void
    {
        $quote = $this->calculator->quote(
            $this->booking(amountPaid: '2310.00', pickupAt: $this->now->addDays(5)),
            RefundReason::CustomerCancellation,
        );

        $this->assertSame('2310.00', $quote->amountPaid);
        $this->assertSame('0.00', $quote->bookingDepositRetained);
        $this->assertSame('150.00', $quote->adminFeeDeducted);
        $this->assertSame('2160.00', $quote->amount);
        $this->assertFalse($quote->insideNoticeWindow);
    }

    public function test_cancelling_inside_24_hours_forfeits_the_booking_deposit_as_well(): void
    {
        $quote = $this->calculator->quote(
            $this->booking(amountPaid: '2310.00', pickupAt: $this->now->addHours(6)),
            RefundReason::CustomerCancellation,
        );

        // Spec §9.1: the deposit is non-refundable, and the fee comes off what
        // is left above it.
        $this->assertSame('1155.00', $quote->bookingDepositRetained);
        $this->assertSame('150.00', $quote->adminFeeDeducted);
        $this->assertSame('1005.00', $quote->amount);
        $this->assertTrue($quote->insideNoticeWindow);
    }

    /**
     * The boundary, pinned deliberately.
     *
     * §9.1 says "more than 24 hours before pickup" keeps the deposit. The
     * instant exactly 24 hours out is not more than 24 hours out, so it is
     * already inside the window. This is precisely the comparison somebody
     * flips while tidying, and it is worth a customer's deposit each time.
     */
    public function test_exactly_24_hours_before_pickup_is_already_inside_the_window(): void
    {
        $quote = $this->calculator->quote(
            $this->booking(amountPaid: '2310.00', pickupAt: $this->now->addHours(24)),
            RefundReason::CustomerCancellation,
        );

        $this->assertTrue($quote->insideNoticeWindow);
        $this->assertSame('1155.00', $quote->bookingDepositRetained);
    }

    public function test_one_second_more_than_24_hours_out_is_outside_the_window(): void
    {
        $quote = $this->calculator->quote(
            $this->booking(amountPaid: '2310.00', pickupAt: $this->now->addHours(24)->addSecond()),
            RefundReason::CustomerCancellation,
        );

        $this->assertFalse($quote->insideNoticeWindow);
        $this->assertSame('0.00', $quote->bookingDepositRetained);
    }

    public function test_the_notice_window_is_read_from_settings_rather_than_hardcoded(): void
    {
        $this->settings->set(SettingKey::CancellationNoticeHours, '48');

        $quote = $this->calculator->quote(
            $this->booking(amountPaid: '2310.00', pickupAt: $this->now->addHours(36)),
            RefundReason::CustomerCancellation,
        );

        // 36 hours out is comfortably outside a 24-hour window and inside a
        // 48-hour one.
        $this->assertSame(48, $quote->noticeWindowHours);
        $this->assertTrue($quote->insideNoticeWindow);
        $this->assertSame('1155.00', $quote->bookingDepositRetained);
    }

    // --- §9.1 No-show -------------------------------------------------------

    public function test_a_no_show_is_treated_as_a_cancellation_inside_24_hours(): void
    {
        $quote = $this->calculator->quote(
            $this->booking(amountPaid: '2310.00', pickupAt: $this->now->subHours(3)),
            RefundReason::NoShow,
        );

        $this->assertSame('1155.00', $quote->bookingDepositRetained);
        $this->assertSame('150.00', $quote->adminFeeDeducted);
        $this->assertSame('1005.00', $quote->amount);
    }

    /**
     * A no-show forfeits the deposit whatever the clock says.
     *
     * Recorded against a pickup still days away, which cannot happen in
     * practice — the point is that the outcome comes from the reason and not
     * from the timing, so a clerk marking one early cannot accidentally hand
     * back a deposit §9.1 says is forfeit.
     */
    public function test_a_no_show_forfeits_the_deposit_even_outside_the_notice_window(): void
    {
        $quote = $this->calculator->quote(
            $this->booking(amountPaid: '2310.00', pickupAt: $this->now->addDays(5)),
            RefundReason::NoShow,
        );

        $this->assertFalse($quote->insideNoticeWindow);
        $this->assertSame('1155.00', $quote->bookingDepositRetained);
    }

    // --- §9.2 Failed KYC ----------------------------------------------------

    public function test_failed_kyc_refunds_everything_less_the_fee_regardless_of_timing(): void
    {
        foreach ([$this->now->addDays(5), $this->now->addHours(2), $this->now->subHour()] as $pickupAt) {
            $quote = $this->calculator->quote(
                $this->booking(amountPaid: '2310.00', pickupAt: $pickupAt),
                RefundReason::FailedKyc,
            );

            // §9.2 is unqualified: "minus flat admin fee, regardless of
            // timing". The deposit is never withheld — the customer failed a
            // check the operator applied at the desk.
            $this->assertSame('0.00', $quote->bookingDepositRetained);
            $this->assertSame('150.00', $quote->adminFeeDeducted);
            $this->assertSame('2160.00', $quote->amount);
        }
    }

    // --- §11 Cross-border ---------------------------------------------------

    public function test_cross_border_paperwork_failure_refunds_in_full_with_no_fee(): void
    {
        $quote = $this->calculator->quote(
            $this->booking(amountPaid: '2310.00', pickupAt: $this->now->addHours(2)),
            RefundReason::CrossBorderPaperworkFailed,
        );

        // Inside the notice window, and it makes no difference: §11 says the
        // failure is operational, so the customer carries none of it.
        $this->assertTrue($quote->insideNoticeWindow);
        $this->assertSame('0.00', $quote->bookingDepositRetained);
        $this->assertSame('0.00', $quote->adminFeeDeducted);
        $this->assertSame('0.00', $quote->adminFeeConfigured);
        $this->assertSame('2310.00', $quote->amount);
    }

    // --- Clamping -----------------------------------------------------------

    public function test_the_fee_never_exceeds_what_is_left_to_refund(): void
    {
        $quote = $this->calculator->quote(
            $this->booking(amountPaid: '100.00', pickupAt: $this->now->addDays(5)),
            RefundReason::CustomerCancellation,
        );

        // K100 held against a K150 fee. The customer gets nothing back and is
        // NOT billed K50 — §9 describes deductions from money held.
        $this->assertSame('100.00', $quote->adminFeeDeducted);
        $this->assertSame('150.00', $quote->adminFeeConfigured);
        $this->assertSame('0.00', $quote->amount);
        $this->assertTrue($quote->adminFeeWasClamped());
        $this->assertFalse($quote->hasAnythingToRefund());
    }

    public function test_the_retained_deposit_never_exceeds_what_was_actually_paid(): void
    {
        $quote = $this->calculator->quote(
            $this->booking(amountPaid: '500.00', pickupAt: $this->now->addHours(2)),
            RefundReason::CustomerCancellation,
        );

        // They part-paid K500 against a K1,155 deposit and cancelled late. All
        // of it is forfeit; nothing remains for the fee to come out of.
        $this->assertSame('500.00', $quote->bookingDepositRetained);
        $this->assertSame('0.00', $quote->adminFeeDeducted);
        $this->assertSame('0.00', $quote->amount);
    }

    public function test_a_booking_that_paid_nothing_is_owed_nothing(): void
    {
        foreach (RefundReason::cases() as $reason) {
            $quote = $this->calculator->quote(
                $this->booking(amountPaid: '0.00', pickupAt: $this->now->addDays(5)),
                $reason,
            );

            $this->assertSame('0.00', $quote->amount, $reason->value.' should owe nothing.');
            $this->assertSame('0.00', $quote->adminFeeDeducted, $reason->value.' should deduct nothing.');
            $this->assertFalse($quote->hasAnythingToRefund());
        }
    }

    /**
     * No reason, at any timing, may ever produce a negative refund.
     *
     * The blanket version of the two clamp tests above: a debt owed the wrong
     * way is not a refund, and a negative here would be stored, approved and
     * eventually handed to somebody as a figure.
     */
    public function test_no_combination_produces_a_negative_amount(): void
    {
        $timings = [
            $this->now->addDays(5),
            $this->now->addHours(24),
            $this->now->addHours(1),
            $this->now->subDay(),
        ];

        foreach (RefundReason::cases() as $reason) {
            foreach ($timings as $pickupAt) {
                foreach (['0.00', '1.00', '150.00', '1155.00', '2310.00'] as $paid) {
                    $quote = $this->calculator->quote(
                        $this->booking(amountPaid: $paid, pickupAt: $pickupAt),
                        $reason,
                    );

                    $this->assertGreaterThanOrEqual(
                        0,
                        bccomp($quote->amount, '0.00', 2),
                        sprintf('%s at %s having paid %s went negative.', $reason->value, $pickupAt, $paid),
                    );
                }
            }
        }
    }

    /**
     * A customer who overpaid gets back what they paid, not what they owed.
     *
     * The calculation starts from money held, never from the grand total. If it
     * read the total instead, an overpayment would be quietly kept.
     */
    public function test_an_overpayment_is_refunded_from_what_was_actually_paid(): void
    {
        $quote = $this->calculator->quote(
            $this->booking(amountPaid: '2500.00', pickupAt: $this->now->addDays(5)),
            RefundReason::CustomerCancellation,
        );

        $this->assertSame('2350.00', $quote->amount);
    }

    // --- The §15.1 placeholder ---------------------------------------------

    public function test_the_quote_reports_a_placeholder_fee_as_such(): void
    {
        // Back to the seeded state: 0.00, flagged.
        $this->settings->set(SettingKey::AdminFeeAmount, '0.00', isPlaceholder: true);

        $quote = $this->calculator->quote(
            $this->booking(amountPaid: '2310.00', pickupAt: $this->now->addDays(5)),
            RefundReason::CustomerCancellation,
        );

        $this->assertTrue($quote->adminFeeIsPlaceholder);
        $this->assertSame('0.00', $quote->adminFeeDeducted);
        $this->assertSame('2310.00', $quote->amount);
    }

    public function test_a_decided_fee_is_not_reported_as_a_placeholder(): void
    {
        $this->settings->set(SettingKey::AdminFeeAmount, '150.00');

        $quote = $this->calculator->quote(
            $this->booking(amountPaid: '2310.00', pickupAt: $this->now->addDays(5)),
            RefundReason::CustomerCancellation,
        );

        $this->assertFalse($quote->adminFeeIsPlaceholder);
    }

    /**
     * A cross-border refund deducts no fee, so the placeholder had no part in
     * its figure and must not be flagged on it. A warning that appears where it
     * does not belong is how people learn to ignore warnings.
     */
    public function test_a_cross_border_refund_is_never_flagged_for_the_placeholder_fee(): void
    {
        $this->settings->set(SettingKey::AdminFeeAmount, '0.00', isPlaceholder: true);

        $quote = $this->calculator->quote(
            $this->booking(amountPaid: '2310.00', pickupAt: $this->now->addDays(5)),
            RefundReason::CrossBorderPaperworkFailed,
        );

        $this->assertFalse($quote->adminFeeIsPlaceholder);
    }

    // --- Money handling -----------------------------------------------------

    /**
     * Every figure leaving the calculator is scaled, however it was set.
     *
     * The `decimal:2` cast on Booking already scales these two columns on read,
     * so this does not prove the calculator's own `Money::of()` calls are
     * load-bearing — it pins the output contract. The calls stay because the
     * settings value beside them arrives from a table with no such cast, and a
     * mixed-scale expression is how '300' and '300.00' end up compared.
     */
    public function test_unscaled_input_is_normalised_throughout(): void
    {
        $quote = $this->calculator->quote(
            $this->booking(amountPaid: '2310', pickupAt: $this->now->addDays(5), deposit: '1155'),
            RefundReason::CustomerCancellation,
        );

        $this->assertSame('2310.00', $quote->amountPaid);
        $this->assertSame('0.00', $quote->bookingDepositRetained);
        $this->assertSame('2160.00', $quote->amount);
    }

    public function test_fractional_amounts_survive_intact(): void
    {
        $this->settings->set(SettingKey::AdminFeeAmount, '99.99');

        $quote = $this->calculator->quote(
            $this->booking(amountPaid: '1000.01', pickupAt: $this->now->addDays(5)),
            RefundReason::CustomerCancellation,
        );

        $this->assertSame('900.02', $quote->amount);
    }

    // --- Fixtures -----------------------------------------------------------

    /**
     * A booking carrying only the three columns the calculator reads.
     *
     * Never saved. See the class docblock.
     */
    private function booking(
        string $amountPaid,
        CarbonImmutable $pickupAt,
        string $deposit = '1155.00',
    ): Booking {
        $booking = new Booking;

        $booking->forceFill([
            'amount_paid' => $amountPaid,
            'booking_deposit_amount' => $deposit,
            'pickup_at' => $pickupAt,
        ]);

        return $booking;
    }
}
