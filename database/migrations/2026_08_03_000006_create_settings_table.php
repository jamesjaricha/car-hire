<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operator-editable configuration.
 *
 * Spec §15 lists twelve values that must be answered before go-live — the flat
 * admin fee, the booking deposit percentage, the short-notice threshold and so
 * on. None of them are hardcoded. They live here, seeded with clearly-marked
 * placeholders, and become editable through the admin panel.
 *
 * The point is that no business rule can silently drift out of date in a
 * constant somewhere, and none of them require a deploy to change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();

            // Drives casting on read: string, integer, decimal, boolean, json.
            $table->string('type')->default('string');

            $table->string('group')->default('general');
            $table->string('label')->nullable();
            $table->text('description')->nullable();

            // True while the seeded value is a placeholder rather than a real
            // business decision. Surfaces the outstanding §15 items in admin.
            $table->boolean('is_placeholder')->default(false);

            $table->timestamps();

            $table->index('group');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
