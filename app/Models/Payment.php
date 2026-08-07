<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentMethodCode;
use App\Enums\PaymentStatus;
use App\Support\Money;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One receipt.
 *
 * `amount` is what actually arrived, which is not necessarily what was asked
 * for. `expected_amount` is what was due when the receipt was raised. Comparing
 * them is how a shortfall is spotted; comparing `amount` against the booking's
 * `balance_due` would not work, because that figure moves as other payments are
 * confirmed.
 *
 * There is no `confirm()` method here and no `confirmed_at` column. Confirmation
 * is an INSERT into `payment_confirmations`, whose unique key is what makes
 * double confirmation impossible rather than merely unlikely. Go through
 * PaymentConfirmationService.
 */
final class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    protected $fillable = [
        'booking_id',
        'operator_id',
        'payment_reference',
        'payment_method_code',
        'status',
        'is_deposit',
        'amount',
        'expected_amount',
        'currency',
        'external_reference',
        'notes',
        'proof_path',
        'proof_uploaded_at',
        'recorded_by_user_id',
        'matched_by_user_id',
        'matched_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'payment_method_code' => PaymentMethodCode::class,

            'is_deposit' => 'boolean',

            'amount' => 'decimal:2',
            'expected_amount' => 'decimal:2',

            'proof_uploaded_at' => 'immutable_datetime',
            'matched_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return BelongsTo<Operator, $this> */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function matchedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'matched_by_user_id');
    }

    /**
     * The confirmation, if there is one. At most one, enforced by a unique key.
     *
     * @return HasOne<PaymentConfirmation, $this>
     */
    public function confirmation(): HasOne
    {
        return $this->hasOne(PaymentConfirmation::class);
    }

    /**
     * Whether this receipt's money counts towards what the customer has paid.
     */
    public function countsTowardsAmountPaid(): bool
    {
        return $this->status->countsTowardsAmountPaid();
    }

    public function isMatched(): bool
    {
        return $this->booking_id !== null;
    }

    /**
     * Whether less arrived than was asked for.
     *
     * Two cases are deliberately NOT shortfalls, and both would otherwise fill
     * a staff queue with rows that need no action:
     *
     * An unmatched receipt has no expectation to fall short of. It is money
     * nobody has attributed yet, not money that is missing.
     *
     * A receipt with nothing against it is unpaid, not underpaid. Every booking
     * awaiting payment starts at zero against its full expected amount, so a
     * literal comparison would report every unpaid booking as short by its
     * entire total. "The customer has not paid" and "the customer sent too
     * little" are different problems with different responses — one is chased,
     * the other is reconciled — and the queue that exists to catch the second
     * is useless if it is full of the first.
     */
    public function hasShortfall(): bool
    {
        if ($this->expected_amount === null) {
            return false;
        }

        if (! Money::isPositive($this->amount)) {
            return false;
        }

        return Money::compare($this->amount, $this->expected_amount) < 0;
    }

    /**
     * How much less arrived than was asked for. Zero when nothing is missing.
     */
    public function shortfallAmount(): string
    {
        if (! $this->hasShortfall()) {
            return Money::ZERO;
        }

        return Money::subtract($this->expected_amount, $this->amount);
    }

    /**
     * Receipts whose money counts towards the booking's paid total.
     *
     * NOTE FOR CALLERS SUMMING THIS: aggregate queries through a relation
     * inherit any default ordering, and MySQL rejects a SELECT with an ORDER BY
     * on a column that is not in the aggregate (error 1140) where SQLite
     * quietly allows it. Call ->reorder() before ->sum().
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCounted(Builder $query): Builder
    {
        return $query->whereIn('status', [
            PaymentStatus::Confirmed,
            PaymentStatus::RefundPending,
        ]);
    }

    /**
     * Money nobody has attributed to a booking yet. The guideline's queue.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUnmatched(Builder $query): Builder
    {
        return $query->whereNull('booking_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereIn('status', [
            PaymentStatus::AwaitingPayment,
            PaymentStatus::ProofSubmitted,
        ]);
    }
}
