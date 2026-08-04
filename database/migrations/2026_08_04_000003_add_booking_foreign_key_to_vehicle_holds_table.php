<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Closes the loop left open when vehicle_holds was created.
 *
 * The column was added then, without a constraint, because `bookings` did not
 * exist yet — the hold mechanism was deliberately built and proven before
 * anything that uses it. Now that the table is here, the relationship becomes
 * enforceable.
 *
 * restrictOnDelete rather than cascade: a booking with a live hold must not be
 * deletable, and bookings are not deleted in any case — they are cancelled,
 * which is a state, not a removal. Cascading would let a delete quietly take
 * the audit-relevant hold with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_holds', function (Blueprint $table): void {
            $table->foreign('booking_id')
                ->references('id')
                ->on('bookings')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_holds', function (Blueprint $table): void {
            $table->dropForeign(['booking_id']);
        });
    }
};
