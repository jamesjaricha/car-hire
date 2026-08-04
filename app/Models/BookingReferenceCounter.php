<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Holds the next booking reference to issue.
 *
 * Never read or increment this directly. BookingReferenceGenerator is the only
 * sanctioned reader, because it takes the row lock that makes the sequence safe
 * under concurrent checkout.
 */
final class BookingReferenceCounter extends Model
{
    protected $fillable = [
        'prefix',
        'next_value',
    ];

    protected function casts(): array
    {
        return [
            'next_value' => 'integer',
        ];
    }
}
