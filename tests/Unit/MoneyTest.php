<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use Tests\TestCase;

final class MoneyTest extends TestCase
{
    public function test_unscaled_values_are_normalised(): void
    {
        // The form a value takes when read straight back from SQL.
        $this->assertSame('300.00', Money::of('300'));
        $this->assertSame('300.50', Money::of('300.5'));
        $this->assertSame('0.00', Money::of('0'));
        $this->assertSame('1250.00', Money::of(1250));
    }

    public function test_absent_values_become_zero(): void
    {
        $this->assertSame('0.00', Money::of(null));
        $this->assertSame('0.00', Money::of(''));
        $this->assertSame('0.00', Money::of('   '));
    }

    public function test_nonsense_is_refused_rather_than_silently_becoming_zero(): void
    {
        // A typo in a price field must not quietly price a hire at nothing.
        $this->expectException(InvalidArgumentException::class);

        Money::of('one thousand');
    }

    public function test_addition_and_subtraction_are_exact(): void
    {
        $this->assertSame('1250.75', Money::add('1000.50', '250.25'));
        $this->assertSame('749.75', Money::subtract('1000.00', '250.25'));
        $this->assertSame('-50.00', Money::subtract('100.00', '150.00'));
    }

    public function test_multiplication_rounds_half_up_rather_than_truncating(): void
    {
        // This is the whole reason the class exists. bcmul at scale 2 would
        // return '977.77', quietly discarding half a ngwee on every booking.
        $this->assertSame('977.78', Money::multiply('1955.55', '0.5'));

        // Demonstrating the behaviour being avoided.
        $this->assertSame('977.77', bcmul('1955.55', '0.5', 2));
    }

    public function test_whole_multiplication_is_unaffected(): void
    {
        $this->assertSame('1950.00', Money::multiply('650.00', 3));
        $this->assertSame('0.00', Money::multiply('650.00', 0));
    }

    public function test_negative_values_round_away_from_zero(): void
    {
        $this->assertSame('-977.78', Money::multiply('-1955.55', '0.5'));
    }

    public function test_percentages_round_half_up(): void
    {
        $this->assertSame('977.78', Money::percentageOf('1955.55', 50));
        $this->assertSame('325.00', Money::percentageOf('650.00', 50));
        $this->assertSame('0.00', Money::percentageOf('0.00', 50));
    }

    /**
     * The invariant that matters: a deposit and its balance must sum back to
     * the total. If they do not, a booking can be a ngwee short of settled
     * forever, and therefore can never be released.
     */
    public function test_a_deposit_and_its_balance_always_sum_to_the_total(): void
    {
        $awkwardTotals = ['1955.55', '0.01', '999.99', '1234.57', '3333.33', '0.05'];

        foreach ($awkwardTotals as $total) {
            $deposit = Money::percentageOf($total, 50);
            $balance = Money::subtract($total, $deposit);

            $this->assertSame(
                Money::of($total),
                Money::add($deposit, $balance),
                "Deposit and balance did not reconstitute a total of {$total}."
            );
        }
    }

    public function test_summing_a_list_is_exact(): void
    {
        $this->assertSame(
            '2295.55',
            Money::sum(['1955.55', '340.00', '0.00', null]),
        );
    }

    public function test_comparison_ignores_how_a_value_was_written(): void
    {
        $this->assertSame(0, Money::compare('300', '300.00'));
        $this->assertSame(1, Money::compare('300.01', '300.00'));
        $this->assertSame(-1, Money::compare('299.99', '300.00'));

        $this->assertTrue(Money::isZero('0'));
        $this->assertTrue(Money::isZero('0.00'));
        $this->assertTrue(Money::isZero(null));
        $this->assertFalse(Money::isZero('0.01'));

        $this->assertTrue(Money::isPositive('0.01'));
        $this->assertFalse(Money::isPositive('0.00'));
        $this->assertFalse(Money::isPositive('-1.00'));
    }
}
