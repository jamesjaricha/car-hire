<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Individual physical vehicles.
 *
 * Bookings are made against a specific vehicle, not a class (spec §8.1), which
 * is what makes the hold mechanism meaningful and double-booking preventable.
 *
 * `daily_rate` and `security_deposit_amount` are NULLABLE OVERRIDES. Null means
 * "inherit from the class", which is the normal case. They exist so a newer or
 * higher-spec unit within a class can be priced differently without inventing a
 * whole new class for it. PricingService is the only place that resolves them.
 *
 * `branch_id` is where the vehicle currently lives. One-way hires are handled
 * by staff arrangement, so this column is moved by hand after a one-way return
 * rather than by automatic relocation logic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('operator_id')->constrained()->restrictOnDelete();
            $table->foreignId('vehicle_class_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();

            $table->string('registration')->unique();
            $table->string('make');
            $table->string('model');
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('colour')->nullable();
            $table->string('transmission')->nullable();
            $table->string('fuel_type')->nullable();
            $table->unsignedTinyInteger('seats')->nullable();

            // Null = inherit from the vehicle class.
            $table->decimal('daily_rate', 12, 2)->nullable();
            $table->decimal('security_deposit_amount', 12, 2)->nullable();

            $table->string('status')->default('available');
            $table->text('notes')->nullable();
            $table->timestamps();

            // Availability searches filter by branch and status together.
            $table->index(['branch_id', 'status']);
            $table->index(['vehicle_class_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};
