<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The source of booking reference numbers.
 *
 * One row per prefix, holding the next value to issue. Taking a row lock on it
 * inside the booking's own transaction is what makes references unique under
 * concurrent checkout — an unlocked read-then-increment is the same race as
 * double-booking, and produces two customers holding reference BR-00042.
 *
 * TRADE-OFF, STATED PLAINLY: because the lock is held for the duration of the
 * booking transaction, concurrent bookings serialise at this row. At the volume
 * this platform is built for that is a non-issue, and in exchange the sequence
 * is gapless and trivially auditable. If throughput ever becomes the constraint,
 * the alternative is an optimistic insert retried on the unique index — more
 * moving parts, and it leaves gaps in the sequence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_reference_counters', function (Blueprint $table): void {
            $table->id();
            $table->string('prefix')->unique();
            $table->unsignedBigInteger('next_value')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_reference_counters');
    }
};
