<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fleet owners.
 *
 * At MVP there is exactly one operator — the business running the platform.
 * The table exists from the start because the commercial plan is to open the
 * platform to other Zambian operators later, and retrofitting an ownership
 * column across the whole schema on live booking data is a far worse job than
 * carrying one mostly-idle table now.
 *
 * Deliberately NOT included yet: global scopes, per-operator permissions,
 * commission tracking. Those belong with the multi-operator work itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operators', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone_e164')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operators');
    }
};
