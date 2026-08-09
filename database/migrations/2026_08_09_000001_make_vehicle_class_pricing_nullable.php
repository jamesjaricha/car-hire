<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Let a vehicle class say "nobody has decided this yet".
 *
 * THE PROBLEM THIS FIXES
 *
 * Three of spec §15's open items live on this table: the security deposit
 * (§15.2), the insurance price (§15.3) and the insurance excess (§15.4). All
 * three were `DECIMAL(12,2) NOT NULL DEFAULT 0`, so an undecided figure and a
 * figure the operator deliberately set to zero were the same value. `docs/
 * OPEN-ITEMS.md` called them placeholders; the database could not tell.
 *
 * That is worse here than it is for a setting, because these are customer-
 * facing. Spec §6 requires the security deposit to appear in search results,
 * checkout, the confirmation email and the T&Cs, and says it "must never first
 * appear at the counter". A class left at the default therefore does not warn
 * anybody — it publishes "no deposit required" to every customer who looks, and
 * the counter asks for K2,500 on collection.
 *
 * Spec §10 does the same for the excess: it must be stated at checkout. Zero
 * states that the customer is liable for nothing.
 *
 * NULL NOW MEANS UNDECIDED. 0.00 MEANS DECIDED, AND ZERO.
 *
 * `PricingService` refuses to price a class carrying a null, and
 * `AvailabilityService` keeps such a class out of search results entirely, so
 * an unpriced class cannot reach a customer at all. That is the protection —
 * the admin panel warning is only how somebody finds out why.
 *
 * WHY THE DEFAULT GOES TOO
 *
 * Leaving `DEFAULT 0` would mean a class created without an explicit figure
 * still arrives decided-and-zero, which is exactly the ambiguity being removed.
 * A new class starts undecided and stays out of sale until somebody prices it.
 *
 * EXISTING ROWS ARE NOT TOUCHED. Anything already carrying a real figure keeps
 * it; anything sitting at the old zero default keeps that too, and reads as a
 * deliberate zero. There is no way to tell those apart retrospectively — the
 * information was never recorded — so the seeded demo classes are the only ones
 * affected locally, and the admin panel's completeness check is what a real
 * operator will use to review the fleet before go-live.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_classes', function (Blueprint $table): void {
            $table->decimal('security_deposit_amount', 12, 2)->nullable()->default(null)->change();
            $table->decimal('insurance_price', 12, 2)->nullable()->default(null)->change();
            $table->decimal('insurance_excess_amount', 12, 2)->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        // Reversible, but not losslessly: a null becomes a zero on the way back,
        // and the two are indistinguishable again afterwards. Rolling this back
        // re-creates the ambiguity rather than restoring a previous state.
        //
        // The nulls have to go before the columns stop accepting them, or the
        // ALTER fails on any row that was left undecided.
        foreach (['security_deposit_amount', 'insurance_price', 'insurance_excess_amount'] as $column) {
            DB::table('vehicle_classes')->whereNull($column)->update([$column => 0]);
        }

        Schema::table('vehicle_classes', function (Blueprint $table): void {
            $table->decimal('security_deposit_amount', 12, 2)->default(0)->nullable(false)->change();
            $table->decimal('insurance_price', 12, 2)->default(0)->nullable(false)->change();
            $table->decimal('insurance_excess_amount', 12, 2)->default(0)->nullable(false)->change();
        });
    }
};
