<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The booking's payment position, spec §7.1.
 *
 * Separate from `status` (§7.2) because spec §7 opens by saying booking states
 * and payment states are two entities that must not be merged. A booking can be
 * `confirmed` while its payment is only `partially_paid` — that is the ordinary
 * case for a 50% deposit, and it is precisely the combination that must not be
 * allowed to reach `vehicle_released`.
 *
 * Derived, never set by hand: PaymentConfirmationService recomputes it from the
 * sum of confirmed receipts alongside `amount_paid` and `balance_due`.
 *
 * Placed next to `balance_due` rather than next to `status`, so that the three
 * money columns it must agree with are all in view together.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->string('payment_status')
                ->default('awaiting_payment')
                ->after('balance_due');

            // Drives the unmatched and outstanding payment views, and the
            // expiry sweep's companion query.
            $table->index(['payment_status', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropIndex(['payment_status', 'status']);
            $table->dropColumn('payment_status');
        });
    }
};
