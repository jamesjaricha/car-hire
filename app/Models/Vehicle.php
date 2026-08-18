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
 *
 * `image_paths` is an override of the same shape and without that hazard: empty
 * means inherit the class gallery, and inheriting is the normal case until
 * somebody has photographed this particular car. Read it through
 * `imagePaths()` / `primaryImagePath()`, never raw — the raw column is only
 * half the answer.
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
        'image_paths',
    ];

    protected function casts(): array
    {
        return [
            'image_paths' => 'array',
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

    /**
     * Photographs of THIS car, ignoring anything inherited from its class.
     *
     * This is the "has somebody actually photographed this registration"
     * question, and it is the one the admin panel's worklist asks. The
     * customer-facing question is `imagePaths()`.
     *
     * @return list<string>
     */
    public function ownImagePaths(): array
    {
        return array_values(array_filter($this->image_paths ?? []));
    }

    public function hasOwnImages(): bool
    {
        return $this->ownImagePaths() !== [];
    }

    /**
     * The gallery a customer should see for this vehicle.
     *
     * The fallback chain the class-level migration promised: this car's own
     * photographs, then its class's, then nothing — at which point the view
     * draws the illustrated silhouette. `x-vehicle-image` owns that last step
     * so the three call sites cannot disagree about it again.
     *
     * Own photographs REPLACE the class gallery rather than being prepended to
     * it. Mixing them would put pictures of a different car directly beside
     * pictures of this one, with nothing on screen saying which is which —
     * which is the exact confusion per-vehicle photographs exist to remove.
     *
     * ⚠ Reaches through to `vehicleClass`. `Model::shouldBeStrict()` is on
     * outside production, so any query feeding this into a card must
     * eager-load that relation or it throws rather than quietly N+1ing.
     * `AvailabilityService` and both public controllers already do.
     *
     * @return list<string>
     */
    public function imagePaths(): array
    {
        $own = $this->ownImagePaths();

        if ($own !== []) {
            return $own;
        }

        return $this->vehicleClass?->imagePaths() ?? [];
    }

    public function primaryImagePath(): ?string
    {
        return $this->imagePaths()[0] ?? null;
    }

    /**
     * Vehicles nobody has photographed yet — the fleet photographer's worklist.
     *
     * Deliberately asks about the vehicle's OWN column and ignores the class
     * fallback. A car inheriting its class's photographs is exactly the case
     * this queue exists to find; treating it as done would empty the list
     * precisely where the work is.
     *
     * Matches `[]` as well as `NULL`, because an emptied Filament upload writes
     * an empty array rather than null — a scope testing only for null would
     * report a cleared gallery as photographed, and the column and the filter
     * would then disagree about the same row.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWithoutImages(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->whereNull('image_paths')
                ->orWhereJsonLength('image_paths', 0);
        });
    }

    public function displayName(): string
    {
        return trim("{$this->make} {$this->model}");
    }
}
