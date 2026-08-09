<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Photographs of the fleet, so a customer can see what they are hiring.
 *
 * WHY ON THE CLASS RATHER THAN THE VEHICLE
 *
 * Customers search by dates and branch and are shown vehicles, but they choose
 * by class — an operator with four Corollas photographs the Corolla, not each
 * registration. Putting the gallery on the class means one upload covers the
 * whole group, which is how a small operator actually works.
 *
 * Per-vehicle photographs are a deliberate later addition rather than an
 * oversight: the front-end resolves an image through a fallback chain, so a
 * `vehicles.image_paths` column can be added and consulted first without any
 * layout changing. See the vehicle card component.
 *
 * WHY A JSON COLUMN RATHER THAN A MEDIA TABLE
 *
 * The gallery is an ordered list of paths belonging to exactly one class, never
 * queried across records, never carrying metadata of its own. A join table would
 * add a model, a relation and an ordering column to express something an array
 * already expresses — and Filament's `FileUpload` writes and reorders a JSON
 * array natively. If images ever need alt text, credits or per-image visibility,
 * that is the point to promote this to a table.
 *
 * Nullable, because a class with no photographs is normal and must render — the
 * customer-facing design is built to work without them and treats a photograph
 * as an improvement rather than a requirement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_classes', function (Blueprint $table): void {
            // Ordered: the first entry is the card thumbnail. Order is the
            // operator's, set by dragging in the admin panel.
            $table->json('image_paths')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_classes', function (Blueprint $table): void {
            $table->dropColumn('image_paths');
        });
    }
};
