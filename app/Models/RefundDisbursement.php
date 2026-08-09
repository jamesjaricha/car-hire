<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\RefundDisbursementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A staff member's statement that a specific sum went back to a customer, with
 * the evidence spec §9.3 requires.
 *
 * One row per refund, and the database refuses a second — see the migration for
 * why this is a table rather than a column on `refunds`. §9.3 requires that the
 * same refund never be disbursed twice, and an UPDATE cannot be made
 * structurally impossible.
 *
 * Nothing writes here except `RefundDisbursementService`, for the same reason
 * nothing writes to `vehicle_holds` except `VehicleHoldService::place()` and
 * nothing writes to `payment_confirmations` except `PaymentConfirmationService`:
 * the guard that makes it safe lives in the service, and a second writer
 * silently removes it.
 */
final class RefundDisbursement extends Model
{
    /** @use HasFactory<RefundDisbursementFactory> */
    use HasFactory;

    protected $fillable = [
        'refund_id',
        'disbursed_by_user_id',
        'branch_id',
        'amount_disbursed',
        'disbursement_reference',
        'disbursed_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount_disbursed' => 'decimal:2',
            'disbursed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Refund, $this> */
    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }

    /** @return BelongsTo<User, $this> */
    public function disbursedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disbursed_by_user_id');
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
