<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\VehicleHoldFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An exclusive claim on one vehicle for one date range.
 *
 * Never create these directly. VehicleHoldService::place() is the only sanctioned
 * writer, because it is the only place that takes the row lock that makes
 * double-booking impossible. A hold written by any other path is a hold that
 * raced.
 */
final class VehicleHold extends Model
{
    /** @use HasFactory<VehicleHoldFactory> */
    use HasFactory;

    protected $fillable = [
        'vehicle_id',
        'booking_id',
        'start_at',
        'end_at',
        'expires_at',
        'released_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'start_at' => 'immutable_datetime',
            'end_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'released_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Vehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /**
     * Holds that still claim their vehicle.
     *
     * A hold stops claiming when it is explicitly released, or when its payment
     * deadline passes. Both conditions are checked here so that a stalled expiry
     * job cannot quietly remove vehicles from sale — the worst case is a hold
     * that lingers in the table, not one that blocks bookings.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeStillClaiming(Builder $query, ?CarbonImmutable $asOf = null): Builder
    {
        return $query
            ->whereNull('released_at')
            ->where('expires_at', '>', $asOf ?? CarbonImmutable::now());
    }

    /**
     * Holds whose range overlaps the given half-open window [$start, $end).
     *
     * The caller is responsible for having already expanded the window by the
     * turnaround buffer. Half-open deliberately: a hire ending exactly when
     * another begins does not overlap.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOverlapping(Builder $query, CarbonImmutable $start, CarbonImmutable $end): Builder
    {
        return $query
            ->where('start_at', '<', $end)
            ->where('end_at', '>', $start);
    }

    public function isReleased(): bool
    {
        return $this->released_at !== null;
    }

    public function hasExpired(?CarbonImmutable $asOf = null): bool
    {
        return $this->expires_at->lessThanOrEqualTo($asOf ?? CarbonImmutable::now());
    }
}
