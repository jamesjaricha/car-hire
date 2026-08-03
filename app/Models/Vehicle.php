<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\VehicleStatus;
use Database\Factories\VehicleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A specific physical vehicle. Bookings and holds are made against these,
 * never against a class.
 *
 * `daily_rate` and `security_deposit_amount` are nullable overrides of the
 * class values. Read them through PricingService rather than directly —
 * a null here means "inherit", and code that reads the column raw will
 * silently price a hire at zero.
 */
final class Vehicle extends Model
{
    /** @use HasFactory<VehicleFactory> */
    use HasFactory;

    protected $fillable = [
        'operator_id',
        'vehicle_class_id',
        'branch_id',
        'registration',
        'make',
        'model',
        'year',
        'colour',
        'transmission',
        'fuel_type',
        'seats',
        'daily_rate',
        'security_deposit_amount',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => VehicleStatus::class,
            'year' => 'integer',
            'seats' => 'integer',
            'daily_rate' => 'decimal:2',
            'security_deposit_amount' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Operator, $this> */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class);
    }

    /** @return BelongsTo<VehicleClass, $this> */
    public function vehicleClass(): BelongsTo
    {
        return $this->belongsTo(VehicleClass::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return HasMany<VehicleHold, $this> */
    public function holds(): HasMany
    {
        return $this->hasMany(VehicleHold::class);
    }

    /**
     * Vehicles that are part of the bookable fleet.
     *
     * This says nothing about whether the vehicle is free on any given date —
     * that is what AvailabilityService is for.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeBookable(Builder $query): Builder
    {
        return $query->where('status', VehicleStatus::Available);
    }

    public function displayName(): string
    {
        return trim("{$this->make} {$this->model}");
    }
}
