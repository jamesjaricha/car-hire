<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InsurancePriceMode;
use Database\Factories\VehicleClassFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A grouping of vehicles that carries the pricing.
 *
 * `security_deposit_amount` here is the REFUNDABLE CASH deposit taken at the
 * counter against damage — not the 50% booking deposit that part-pays the hire.
 * The spec calls conflating those two the single most likely misreading.
 *
 * Money attributes are cast to `decimal:2`, which yields strings. That is
 * deliberate: they feed straight into bcmath without ever becoming floats.
 */
final class VehicleClass extends Model
{
    /** @use HasFactory<VehicleClassFactory> */
    use HasFactory;

    protected $fillable = [
        'operator_id',
        'name',
        'slug',
        'description',
        'daily_rate',
        'insurance_price',
        'insurance_price_mode',
        'insurance_excess_amount',
        'security_deposit_amount',
        'turnaround_buffer_minutes',
        'display_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'daily_rate' => 'decimal:2',
            'insurance_price' => 'decimal:2',
            'insurance_price_mode' => InsurancePriceMode::class,
            'insurance_excess_amount' => 'decimal:2',
            'security_deposit_amount' => 'decimal:2',
            'turnaround_buffer_minutes' => 'integer',
            'display_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Operator, $this> */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class);
    }

    /** @return HasMany<Vehicle, $this> */
    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
