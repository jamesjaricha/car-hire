<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Exclusive claims on a specific vehicle for a specific date range.
 *
 * A hold is placed the moment a booking reaches pending_payment and is released
 * when payment is confirmed, the deadline lapses, or the booking is cancelled.
 *
 * HOW DOUBLE-BOOKING IS PREVENTED
 * -------------------------------
 * The primary guarantee is transactional: VehicleHoldService takes a
 * `lockForUpdate()` on the vehicle row before checking for overlaps and
 * inserting. Two simultaneous requests for the same vehicle serialise at that
 * lock, so the second one sees the first one's committed hold. PostgreSQL would
 * let us express this as an exclusion constraint over a time range; MySQL has
 * no equivalent, so the guarantee lives in exactly one service method and is
 * protected by a concurrency test rather than by the schema.
 *
 * `is_active` is a secondary net. It holds 1 while the hold is live and NULL
 * once released. Because MySQL treats NULLs as distinct in a unique index,
 * released rows drop out of the constraint automatically, while two live holds
 * covering the identical range on the same vehicle collide at the database.
 * This catches exact duplicates only — partial overlaps are caught by the lock,
 * not by this index. It is insurance, not the mechanism.
 *
 * `booking_id` has no foreign key yet because `bookings` does not exist until
 * the booking engine is built. The constraint is added by that migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_holds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->restrictOnDelete();

            // FK added by the bookings migration in the next phase.
            $table->unsignedBigInteger('booking_id')->nullable();

            // The hire window itself, treated as half-open: [start_at, end_at).
            $table->dateTime('start_at');
            $table->dateTime('end_at');

            // When this claim lapses if payment is not confirmed.
            $table->dateTime('expires_at');

            $table->dateTime('released_at')->nullable();

            // 1 while live, NULL once released. See the note above.
            $table->tinyInteger('is_active')->nullable()->default(1);

            $table->timestamps();

            $table->unique(
                ['vehicle_id', 'start_at', 'end_at', 'is_active'],
                'vehicle_holds_active_range_unique'
            );

            // Drives the overlap lookup inside the lock.
            $table->index(['vehicle_id', 'start_at', 'end_at'], 'vehicle_holds_overlap_index');

            // Drives the expiry sweep.
            $table->index(['expires_at', 'released_at'], 'vehicle_holds_expiry_index');

            $table->index('booking_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_holds');
    }
};
