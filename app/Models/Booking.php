<?php

declare(strict_types=1);

namespace App\Models;

use App\DataTransferObjects\TransitionContext;
use App\Enums\BookingPaymentStatus;
use App\Enums\BookingStatus;
use App\Enums\PaymentMethodCode;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Factories\BookingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A hire.
 *
 * Never set `status` directly. Go through BookingStateMachine, which is the
 * only thing that knows which moves the specification permits and which guards
 * apply — assigning the column bypasses both, and a booking that reached
 * `vehicle_released` without passing the guards is a car handed to someone who
 * has not paid.
 *
 * The `vehicle_*` and `daily_rate_at_booking` columns are a snapshot of what
 * was agreed, not a cache of the current vehicle. Read them when showing the
 * customer what they booked; read the relations only when you specifically want
 * the vehicle as it is now.
 */
final class Booking extends Model
{
    /** @use HasFactory<BookingFactory> */
    use HasFactory;

    protected $fillable = [
        'reference',
        'operator_id',
        'customer_id',
        'vehicle_id',
        'vehicle_class_id',
        'pickup_branch_id',
        'dropoff_branch_id',
        'pickup_at',
        'dropoff_at',
        'status',
        'payment_method_code',
        'payment_deadline_at',
        'payment_reminder_at',
        'is_short_notice',
        'hire_total',
        'insurance_total',
        'extras_total',
        'cross_border_total',
        'grand_total',
        'currency',
        'pay_in_full',
        'booking_deposit_amount',
        'deposit_percentage',
        'amount_paid',
        'balance_due',
        'payment_status',
        'security_deposit_amount',
        'security_deposit_collected_at',
        'security_deposit_returned_at',
        'security_deposit_returned_amount',
        'insurance_excess_amount',
        'cross_border_country',
        'terms_version',
        'terms_accepted_at',
        'vehicle_registration',
        'vehicle_make',
        'vehicle_model',
        'vehicle_class_name',
        'daily_rate_at_booking',
        'chargeable_days',
        'confirmed_at',
        'vehicle_released_at',
        'completed_at',
        'cancelled_at',
        'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => BookingStatus::class,
            'payment_status' => BookingPaymentStatus::class,
            'payment_method_code' => PaymentMethodCode::class,

            'pickup_at' => 'immutable_datetime',
            'dropoff_at' => 'immutable_datetime',
            'payment_deadline_at' => 'immutable_datetime',
            'payment_reminder_at' => 'immutable_datetime',
            'security_deposit_collected_at' => 'immutable_datetime',
            'security_deposit_returned_at' => 'immutable_datetime',
            'terms_accepted_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
            'vehicle_released_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',

            'is_short_notice' => 'boolean',
            'pay_in_full' => 'boolean',

            'hire_total' => 'decimal:2',
            'insurance_total' => 'decimal:2',
            'extras_total' => 'decimal:2',
            'cross_border_total' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'booking_deposit_amount' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'balance_due' => 'decimal:2',
            'security_deposit_amount' => 'decimal:2',
            'security_deposit_returned_amount' => 'decimal:2',
            'insurance_excess_amount' => 'decimal:2',
            'daily_rate_at_booking' => 'decimal:2',

            'deposit_percentage' => 'integer',
            'chargeable_days' => 'integer',
        ];
    }

    /** @return BelongsTo<Operator, $this> */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Vehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /** @return BelongsTo<VehicleClass, $this> */
    public function vehicleClass(): BelongsTo
    {
        return $this->belongsTo(VehicleClass::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function pickupBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'pickup_branch_id');
    }

    /** @return BelongsTo<Branch, $this> */
    public function dropoffBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'dropoff_branch_id');
    }

    /** @return HasMany<VehicleHold, $this> */
    public function holds(): HasMany
    {
        return $this->hasMany(VehicleHold::class);
    }

    /**
     * Every receipt raised against this booking, confirmed or not.
     *
     * Do not sum this to find what has been paid — most of these rows are
     * expectations rather than money. Use `countedPayments()`, and read the
     * ordering warning on it.
     *
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * The receipts whose money counts towards `amount_paid`.
     *
     * ⚠ Summing through a relation inherits its ordering, and MySQL refuses an
     * aggregate SELECT carrying an ORDER BY on a column outside the aggregate
     * (error 1140) where SQLite passes it silently. Always:
     *
     *     $booking->countedPayments()->reorder()->sum('amount')
     *
     * and normalise the result through Money::of() — SQL returns '300', not
     * '300.00', and the difference fails every exact string assertion.
     *
     * @return HasMany<Payment, $this>
     */
    public function countedPayments(): HasMany
    {
        return $this->payments()->counted();
    }

    /**
     * The facts the state machine needs to decide whether this booking may
     * move to the state being requested.
     *
     * Built here so no caller has to remember which columns the guards read —
     * forgetting one would mean releasing a vehicle without checking it.
     */
    public function transitionContext(): TransitionContext
    {
        return new TransitionContext(
            balanceDue: Money::of($this->balance_due),
            securityDepositCollected: $this->security_deposit_collected_at !== null,
            // KYC verification has nowhere to be read from yet. Left null
            // deliberately rather than defaulted to true, so that when the
            // guard begins enforcing it nothing silently passes.
            kycVerified: null,
        );
    }

    public function hasOutstandingBalance(): bool
    {
        return Money::isPositive($this->balance_due);
    }

    public function isCrossBorder(): bool
    {
        return $this->cross_border_country !== null;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAwaitingPayment(Builder $query): Builder
    {
        return $query->where('status', BookingStatus::PendingPayment);
    }

    /**
     * Unpaid bookings whose deadline has passed. Drives the expiry sweep.
     *
     * Short-notice bookings have no deadline and are excluded automatically by
     * the null comparison — they are never cancelled for non-payment, because
     * they were never given a window in which to pay.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePastPaymentDeadline(Builder $query, ?CarbonImmutable $asOf = null): Builder
    {
        return $query
            ->where('status', BookingStatus::PendingPayment)
            ->whereNotNull('payment_deadline_at')
            ->where('payment_deadline_at', '<=', $asOf ?? CarbonImmutable::now());
    }

    /**
     * Bookings the expiry sweep deliberately refused to touch.
     *
     * The deadline has passed, but the customer has paid something — so
     * cancelling them is a decision about a refund, and spec §9.3 requires a
     * refund to be requested and approved by two different people, neither of
     * whom is a scheduled job. They wait here for a person.
     *
     * This is a queue, and it needs a screen. Without one these accumulate
     * silently, each holding somebody's money.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeStalledAfterDeadline(Builder $query, ?CarbonImmutable $asOf = null): Builder
    {
        return $query
            ->pastPaymentDeadline($asOf)
            ->where('amount_paid', '>', 0);
    }
}
