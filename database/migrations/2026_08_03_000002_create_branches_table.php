<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Physical locations where vehicles are collected and returned.
 *
 * Opening hours and the after-hours pickup policy are spec §15 open items.
 * They are stored nullable so branches can be created before the policy is
 * settled, rather than forcing a guess into the schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('operator_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('code');
            $table->string('city');
            $table->string('address')->nullable();
            $table->string('phone_e164')->nullable();

            // Nullable until the operating-hours policy is confirmed (spec §15.8).
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();
            $table->boolean('after_hours_pickup')->default(false);

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['operator_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
