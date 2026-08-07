<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PaymentConfirmationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A staff member's statement that a specific sum of money actually arrived.
 *
 * One row per payment, and the database refuses a second — see the migration
 * for why this is a table rather than two columns on `payments`. Spec §12
 * requires duplicate confirmation to be structurally impossible, and an UPDATE
 * cannot be made structurally impossible.
 *
 * Nothing writes here except PaymentConfirmationService, for the same reason
 * nothing writes to `vehicle_holds` except VehicleHoldService::place(): the
 * guard that makes it safe lives in the service, and a second writer silently
 * removes it.
 */
final class PaymentConfirmation extends Model
{
    /** @use HasFactory<PaymentConfirmationFactory> */
    use HasFactory;

    protected $fillable = [
        'payment_id',
        'confirmed_by_user_id',
        'branch_id',
        'amount_confirmed',
        'confirmed_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount_confirmed' => 'decimal:2',
            'confirmed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
