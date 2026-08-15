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
        'image_paths',
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
            'image_paths' => 'array',
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

    /**
     * The three figures spec §15 leaves to the business, and their columns.
     *
     * Null means undecided. 0.00 means somebody decided it is zero. Before the
     * 2026-08-09 migration these were the same value, which meant an unpriced
     * class published "no deposit required" to customers rather than warning
     * anybody. See that migration.
     *
     * @var array<string, string>
     */
    public const PRICING_DECISIONS = [
        'security_deposit_amount' => 'Refundable security deposit (spec §6, §15.2)',
        'insurance_price' => 'Damage waiver price (spec §10, §15.3)',
        'insurance_excess_amount' => 'Insurance excess (spec §10, §15.4)',
    ];

    /**
     * The photograph a card or a hero should use, or null.
     *
     * Null is an ordinary answer, not a missing asset: the customer-facing
     * design is built to work without photographs and treats one as an
     * improvement. Callers render an illustrated silhouette instead.
     *
     * When per-vehicle photographs arrive this is where the fallback chain
     * grows — vehicle first, then class, then silhouette — and nothing above it
     * needs to change.
     */
    public function primaryImagePath(): ?string
    {
        $paths = array_values(array_filter($this->image_paths ?? []));

        return $paths[0] ?? null;
    }

    public function hasImages(): bool
    {
        return $this->primaryImagePath() !== null;
    }

    /**
     * @return list<string>
     */
    public function imagePaths(): array
    {
        return array_values(array_filter($this->image_paths ?? []));
    }

    /**
     * Whether this class can lawfully be sold.
     *
     * Spec §6 requires the security deposit to be shown from search results
     * onward and never to first appear at the counter; §10 requires the excess
     * to be stated at checkout. A class missing either cannot be quoted honestly,
     * so `PricingService` refuses it and `AvailabilityService` keeps it out of
     * search entirely.
     *
     * `daily_rate` is not in this list because it has never been nullable —
     * a class has always had to have one.
     */
    public function isFullyPriced(): bool
    {
        return $this->missingPricingDecisions() === [];
    }

    /**
     * Which figures nobody has decided yet, as human labels.
     *
     * Returned rather than a bare boolean because the admin panel has to tell
     * somebody what to go and enter, and "this class is incomplete" is not
     * enough to act on.
     *
     * @return list<string>
     */
    public function missingPricingDecisions(): array
    {
        $missing = [];

        foreach (self::PRICING_DECISIONS as $column => $label) {
            if ($this->getAttribute($column) === null) {
                $missing[] = $label;
            }
        }

        return $missing;
    }

    /**
     * Classes that can be sold.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeFullyPriced(Builder $query): Builder
    {
        foreach (array_keys(self::PRICING_DECISIONS) as $column) {
            $query->whereNotNull($column);
        }

        return $query;
    }

    /**
     * The review queue: classes waiting on a decision from the business.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAwaitingPricingDecisions(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            foreach (array_keys(self::PRICING_DECISIONS) as $column) {
                $query->orWhereNull($column);
            }
        });
    }

    /**
     * Classes with no photograph.
     *
     * Unlike a missing pricing decision this blocks nothing — the customer-facing
     * design renders an illustrated panel instead and the class still sells. It
     * is a presentation gap, so it belongs in a column the fleet manager can
     * scan rather than in a warning that implies something is broken.
     *
     * `image_paths` is a JSON column, and an emptied Filament upload leaves `[]`
     * rather than null. Both mean "no photograph", so both are matched — testing
     * only for null would report an emptied gallery as populated.
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
}
