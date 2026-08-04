<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Every monetary calculation in the platform goes through here.
 *
 * Values are decimal strings at a fixed scale, manipulated with bcmath. Never
 * float — the guideline's second non-negotiable, and the reason is that a
 * float cannot represent 0.10 exactly, so totals drift by fractions of a ngwee
 * and reconciliation against a bank statement stops adding up.
 *
 * TWO TRAPS THIS CLASS EXISTS TO CLOSE
 *
 * 1. Unscaled input. A value read back from SQL arrives as '300', not '300.00'.
 *    Arithmetic on it is numerically right but `assertSame('300.00', ...)`
 *    fails, and so does any string comparison. Everything is normalised on the
 *    way in.
 *
 * 2. bcmath truncates, it does not round. `bcmul('1955.55', '0.5', 2)` gives
 *    '977.77', quietly discarding half a ngwee. Over a deposit on every
 *    booking that is a real, if small, leak — and worse, deposit plus balance
 *    would no longer equal the total. multiply() rounds half up instead.
 */
final class Money
{
    public const ZERO = '0.00';

    /**
     * Bring a value to the configured scale.
     */
    public static function of(string|int|null $value): string
    {
        if ($value === null) {
            return self::normalise('0');
        }

        $value = trim((string) $value);

        if ($value === '') {
            return self::normalise('0');
        }

        if (! is_numeric($value)) {
            throw new InvalidArgumentException("[{$value}] is not a monetary amount.");
        }

        return self::normalise($value);
    }

    public static function add(string|int|null $a, string|int|null $b): string
    {
        return bcadd(self::of($a), self::of($b), self::scale());
    }

    public static function subtract(string|int|null $a, string|int|null $b): string
    {
        return bcsub(self::of($a), self::of($b), self::scale());
    }

    /**
     * Multiply, rounding half up rather than truncating.
     */
    public static function multiply(string|int|null $amount, string|int $multiplier): string
    {
        // Computed at a wider scale first so the rounding decision is made on
        // the real value rather than on an already-truncated one.
        $wide = bcmul(self::of($amount), (string) $multiplier, self::scale() + 4);

        return self::round($wide);
    }

    /**
     * A percentage of an amount, rounded half up.
     *
     * Used for the 50% booking deposit. Pair it with subtract() to derive the
     * balance rather than calculating that separately — otherwise the two
     * roundings can disagree and the parts stop summing to the whole.
     */
    public static function percentageOf(string|int|null $amount, string|int $percent): string
    {
        $wide = bcdiv(
            bcmul(self::of($amount), (string) $percent, self::scale() + 6),
            '100',
            self::scale() + 4,
        );

        return self::round($wide);
    }

    /**
     * @return int -1, 0 or 1
     */
    public static function compare(string|int|null $a, string|int|null $b): int
    {
        return bccomp(self::of($a), self::of($b), self::scale());
    }

    public static function isZero(string|int|null $value): bool
    {
        return self::compare($value, self::ZERO) === 0;
    }

    public static function isPositive(string|int|null $value): bool
    {
        return self::compare($value, self::ZERO) === 1;
    }

    /**
     * @param  list<string|int|null>  $amounts
     */
    public static function sum(array $amounts): string
    {
        $total = self::ZERO;

        foreach ($amounts as $amount) {
            $total = self::add($total, $amount);
        }

        return $total;
    }

    public static function scale(): int
    {
        return (int) config('carhire.money_scale', 2);
    }

    /**
     * Round half up to the configured scale.
     *
     * bcadd truncates, so adding half of the smallest unit before truncating
     * produces the same result as rounding half away from zero.
     */
    private static function round(string $wideValue): string
    {
        $scale = self::scale();
        $halfUnit = '0.'.str_repeat('0', $scale).'5';

        return bccomp($wideValue, '0', $scale + 4) >= 0
            ? bcadd($wideValue, $halfUnit, $scale)
            : bcsub($wideValue, $halfUnit, $scale);
    }

    private static function normalise(string $value): string
    {
        return bcadd($value, '0', self::scale());
    }
}
