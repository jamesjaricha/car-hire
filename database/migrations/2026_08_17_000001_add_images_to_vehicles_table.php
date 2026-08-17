<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Photographs of the actual car, not one like it.
 *
 * WHY THE CLASS-LEVEL GALLERY WAS NOT ENOUGH
 *
 * The 2026-08-09 migration that added `vehicle_classes.image_paths` argued that
 * an operator with four Corollas photographs the Corolla. That is true of the
 * operator's effort and false of the customer's decision.
 *
 * This platform sells a SPECIFIC vehicle. Search returns individual cars,
 * `/vehicles/{id}` quotes one, and `VehicleHoldService::place()` locks that exact
 * row — the customer is hiring a particular registration, and two cars in one
 * class differ in colour, trim, age and condition. Showing a photograph of a
 * different car is the thing a customer cannot verify and therefore cannot
 * trust: the operator's own words for it were that it "looks like a scam
 * website". With no card gateway behind the checkout, trust is the whole of what
 * persuades somebody to transfer real money to a bank account, so this is not a
 * cosmetic concern.
 *
 * WHY THE CLASS COLUMN STAYS
 *
 * This is an override, not a replacement, and the distinction is what makes
 * partial adoption safe. `Vehicle::imagePaths()` resolves vehicle, then class,
 * then the illustrated silhouette — so an operator can photograph six cars this
 * week and twelve next month and the site never looks worse in between. The
 * class gallery also keeps a job of its own: the home page cards are classes,
 * not vehicles, so that is the shop-window image for a whole range.
 *
 * The shape is deliberately the same as `daily_rate` and
 * `security_deposit_amount` — a nullable vehicle-level override of a class
 * figure — but WITHOUT their hazard. An empty money override coerced to 0.00
 * prices a hire at nothing; an empty gallery just inherits. Nothing here needs
 * `dehydrateStateUsing`, and nothing here is a pricing power, which is why this
 * field sits happily under `fleet.manage-vehicles` alongside the rest of the
 * vehicle form rather than needing `fleet.manage`.
 *
 * JSON for the same reasons as the class column: an ordered list of paths
 * belonging to exactly one record, never queried across records, never carrying
 * metadata. Filament's `FileUpload` writes and reorders it natively.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table): void {
            // Ordered: the first entry is the card thumbnail and the hero on the
            // vehicle page. The order is the operator's, set by dragging.
            $table->json('image_paths')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table): void {
            $table->dropColumn('image_paths');
        });
    }
};
