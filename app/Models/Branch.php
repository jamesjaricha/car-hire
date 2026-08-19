<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A location where vehicles are collected and returned.
 */
final class Branch extends Model
{
    /** @use HasFactory<BranchFactory> */
    use HasFactory;

    protected $fillable = [
        'operator_id',
        'name',
        'code',
        'city',
        'address',
        'phone_e164',
        'opens_at',
        'closes_at',
        'after_hours_pickup',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'after_hours_pickup' => 'boolean',
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
     * Whether this branch has published opening hours at all.
     *
     * Both halves are required. One time without the other says nothing useful
     * — "opens 08:00" with no closing time tells a customer planning a
     * collection precisely as much as silence does, while looking like an
     * answer.
     */
    public function publishesHours(): bool
    {
        return $this->opens_at !== null && $this->closes_at !== null;
    }

    /**
     * "08:00 – 17:00", or null when the operator has not said.
     *
     * Spec §15.8 is unanswered by default and the columns are nullable for that
     * reason. Returning null rather than a plausible default is the whole point:
     * a guess here has somebody drive to a closed gate. See ARCHITECTURE §14 —
     * same principle as the §15 pricing fields, with the difference that
     * missing hours withhold nothing from sale.
     */
    public function openingHoursLabel(): ?string
    {
        if (! $this->publishesHours()) {
            return null;
        }

        return self::asClockTime((string) $this->opens_at)
            .' – '
            .self::asClockTime((string) $this->closes_at);
    }

    /**
     * Branches whose hours nobody has decided — the queue for the §15.8 answer.
     *
     * Either column being null counts, matching `publishesHours()`. A scope and
     * a predicate that disagree about the same row is how a badge ends up
     * contradicting the screen it sits on.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWithoutPublishedHours(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->whereNull('opens_at')->orWhereNull('closes_at');
        });
    }

    /**
     * MySQL hands back `TIME` as `08:00:00`; Filament's picker may submit
     * `08:00`. Both trim to the same thing, and neither is worth a cast that
     * would turn a wall-clock time into a date somewhere in 1970.
     */
    private static function asClockTime(string $value): string
    {
        return substr($value, 0, 5);
    }
}
