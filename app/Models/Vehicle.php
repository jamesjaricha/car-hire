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
     * THIS CAR'S OWN PHOTOGRAPHS, OR NOTHING.
     *
     * It briefly fell back to the class gallery, which is what the original
     * class-level migration anticipated. The operator removed that on
     * 2026-08-18, and he was right: a class photograph appearing beside a
     * specific registration is the misrepresentation this whole feature exists
     * to remove, and softening it with a "these are not of this car" caption
     * only made the page apologise for showing the wrong thing rather than not
     * show it.
     *
     * So class photographs now appear on the HOME PAGE only, where a card
     * stands for a range rather than a car. Everywhere a specific vehicle is
     * shown, it is that vehicle or the illustrated silhouette — and the
     * silhouette is deliberately a drawing, so nobody can mistake it for the
     * car either.
     *
     * The consequence is intentional: a fleet that is only part-photographed
     * shows more silhouettes than it used to. An honest gap beats a
     * plausible-looking substitute.
     *
     * @return list<string>
     */
    public function imagePaths(): array
    {
        return $this->ownImagePaths();
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
