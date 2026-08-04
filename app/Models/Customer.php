<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Someone who hires a vehicle.
 *
 * Records are created automatically at checkout from an email and phone; guests
 * never register. `password` stays null until the customer accepts the
 * set-a-password invitation sent AFTER their booking, never before.
 *
 * Duplicates are expected and permitted — see the migration for why. Never add
 * a unique constraint to email or phone here.
 *
 * `phone_e164` is the only column that may be used to match a customer, and it
 * must only ever be queried with a value produced by PhoneNormaliser.
 */
final class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    protected $fillable = [
        'full_name',
        'email',
        'phone_e164',
        'phone_raw',
        'phone_region',
        'email_verified_at',
        'phone_verified_at',
        'password',
        'needs_staff_review',
        'review_reason',
        'possible_duplicate_of_customer_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'immutable_datetime',
            'phone_verified_at' => 'immutable_datetime',
            'password' => 'hashed',
            'needs_staff_review' => 'boolean',
        ];
    }

    /**
     * The record this one probably duplicates, if any.
     *
     * @return BelongsTo<self, $this>
     */
    public function possibleDuplicateOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'possible_duplicate_of_customer_id');
    }

    public function hasAccount(): bool
    {
        return $this->password !== null;
    }

    public function isVerified(): bool
    {
        return $this->email_verified_at !== null || $this->phone_verified_at !== null;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeNeedingReview(Builder $query): Builder
    {
        return $query->where('needs_staff_review', true);
    }
}
