<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vehicle groupings that carry the pricing.
 *
 * A class sets the daily rate, the mandatory damage waiver, the excess the
 * customer remains liable for, and the refundable cash security deposit.
 * Individual vehicles may override the rate and deposit; everything else is
 * decided here.
 *
 * Note the two distinct deposit concepts the spec warns about (§5, §6):
 * `security_deposit_amount` on this table is the REFUNDABLE CASH deposit taken
 * at the counter for damage. It is not, and must never be confused with, the
 * 50% booking deposit that part-pays the hire — that is a percentage of the
 * grand total and lives on the booking.
 *
 * All money is DECIMAL(12,2) and is manipulated with bcmath as strings.
 * Never FLOAT, never DOUBLE.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_classes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('operator_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();

            $table->decimal('daily_rate', 12, 2);

            // Mandatory damage waiver — spec §10. Included in every displayed price.
            $table->decimal('insurance_price', 12, 2)->default(0);
            $table->string('insurance_price_mode')->default('per_day');
            $table->decimal('insurance_excess_amount', 12, 2)->default(0);

            // Refundable cash deposit taken at the branch on pickup — spec §6.
            $table->decimal('security_deposit_amount', 12, 2)->default(0);

            // Gap required between consecutive hires for cleaning and inspection.
            $table->unsignedInteger('turnaround_buffer_minutes')->default(120);

            $table->unsignedInteger('display_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['operator_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_classes');
    }
};
