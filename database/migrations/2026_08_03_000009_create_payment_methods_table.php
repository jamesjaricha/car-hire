<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How customers may pay. Spec §4.
 *
 * At MVP every enabled method is offline and manually verified: cash, bank
 * transfer, MTN Mobile Money and Airtel Money. Mobile money is NOT a gateway
 * integration — it is verification against a merchant or till number, exactly
 * like a bank transfer. No API, no PCI scope, no gateway credentials.
 *
 * Card methods exist as rows so they can be shown greyed out as "Coming Soon",
 * but `enabled` is false and that is checked server-side on every submission.
 * Greying out a button in the UI is cosmetic; a manipulated request must be
 * refused by the application.
 *
 * `hold_duration_hours` is what the deadline calculator uses: cash 24, bank
 * transfer 48, mobile money 6 (spec §8.1). The effective deadline is the lesser
 * of that and pickup minus two hours.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table): void {
            $table->id();

            // cash | bank_transfer | mtn_momo | airtel_money | debit_card | credit_card
            $table->string('code')->unique();
            $table->string('label');

            // offline_cash | offline_transfer | offline_mobile_money | card_gateway
            $table->string('type');

            $table->boolean('enabled')->default(false);
            $table->unsignedInteger('display_order')->default(0);
            $table->boolean('requires_manual_confirmation')->default(true);

            // Shown to the customer on confirmation. Supports merge fields such
            // as the payment reference and amount due.
            $table->text('instructions_template')->nullable();

            // Bank name, account number, till number and so on.
            $table->json('account_details')->nullable();

            // Environment variable that can force this method off without a
            // database change. Spec §4.
            $table->string('feature_flag')->nullable();

            // Hours before pickup below which this method cannot be offered.
            $table->unsignedInteger('min_lead_time_hours')->nullable();

            // How long this method holds a vehicle before the deadline lapses.
            $table->unsignedInteger('hold_duration_hours')->default(24);

            $table->timestamps();

            $table->index(['enabled', 'display_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};
