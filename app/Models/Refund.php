<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentMethodCode;
use App\Enums\RefundReason;
use App\Enums\RefundStatus;
use App\Support\Money;
use Database\Factories\RefundFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Money owed back to a customer. Spec §9.
 *
 * The amounts on this row are FROZEN at the moment it was raised and must never
 * be recomputed — see the migration. A refund quoted at K1,005 stays K1,005 even
 * if the admin fee changes that afternoon or another receipt is confirmed
 * against the booking.
 *
 * There is no `disburse()` here and no `disbursed_at` column. Disbursement is an
 * INSERT into `refund_disbursements`, whose unique key on `refund_id` is what
 * makes §9.3's "never disbursed twice" structural rather than hopeful — the same
 * argument as `payment_confirmations`. Go through `RefundDisbursementService`.
 *
 * Nor is `status` assigned by hand. `RefundRequestService` and
 * `RefundDisbursementService` own it, because each move has a permission, a
 * two-person rule or an audit entry attached to it.
 */
final class Refund extends Model
{
    /** @use HasFactory<RefundFactory> */
    use HasFactory;

    protected $fillable = [
        'booking_id',
        'operator_id',
        'reason',
        'status',
        'method',
        'amount_paid_at_request',
        'booking_deposit_retained',
        'admin_fee_configured',
        'admin_fee_deducted',
        'amount',
        'admin_fee_was_placeholder',
        'currency',
        'requested_by_user_id',
        'requested_at',
        'approved_by_user_id',
        'approved_at',
        'rejected_by_user_id',
        'rejected_at',
        'rejection_reason',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'reason' => RefundReason::class,
            'status' => RefundStatus::class,
            'method' => PaymentMethodCode::class,

            'amount_paid_at_request' => 'decimal:2',
            'booking_deposit_retained' => 'decimal:2',
            'admin_fee_configured' => 'decimal:2',
            'admin_fee_deducted' => 'decimal:2',
            'amount' => 'decimal:2',

            'admin_fee_was_placeholder' => 'boolean',

            'requested_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'rejected_at' => 'immutable_datetime',
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
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by_user_id');
    }

    /**
     * The payout, if it has happened. At most one, enforced by a unique key.
     *
     * @return HasOne<RefundDisbursement, $this>
     */
    public function disbursement(): HasOne
    {
        return $this->hasOne(RefundDisbursement::class);
    }

    /**
     * Whether the money has actually left.
     *
     * Reads the status column, which is a summary. The authority is the
     * disbursement row; if the two ever disagree, believe the row.
     */
    public function isDisbursed(): bool
    {
        return $this->status === RefundStatus::Disbursed;
    }

    /**
     * Whether the operator is holding money it has agreed to give back.
     */
    public function awaitsPayout(): bool
    {
        return $this->status->awaitsPayout();
    }

    /**
     * Whether anything is actually owed.
     *
     * A refund of nothing is a legitimate record — a late cancellation that
     * forfeits exactly what was paid still gets one, because "we looked and
     * nothing was due" is a different statement from silence. It is worth
     * knowing which kind you are holding before offering to pay it out.
     */
    public function hasAnythingToPay(): bool
    {
        return Money::isPositive($this->amount);
    }

    /**
     * Refunds waiting for §9.3's second person.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAwaitingApproval(Builder $query): Builder
    {
        return $query->where('status', RefundStatus::Requested);
    }

    /**
     * Approved, not yet paid. Somebody is waiting for their money.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAwaitingPayout(Builder $query): Builder
    {
        return $query->where('status', RefundStatus::Approved);
    }

    /**
     * Everything still in play — the two queues together.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [RefundStatus::Requested, RefundStatus::Approved]);
    }

    /**
     * Refunds whose money has actually gone back.
     *
     * This is the set `BookingLedger` subtracts from confirmed receipts, and it
     * is deliberately narrow: an approved refund is money still in the till.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeDisbursed(Builder $query): Builder
    {
        return $query->where('status', RefundStatus::Disbursed);
    }
}
