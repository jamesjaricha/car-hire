<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE POINT OF THIS TABLE IS THE UNIQUE KEY ON payment_id.
 *
 * Spec §12: "Duplicate confirmation of the same payment must be structurally
 * impossible, not merely discouraged." The developer guideline §1.3 puts the
 * same requirement as a non-negotiable and names the cause: a double-clicked
 * confirm button.
 *
 * The obvious design — `confirmed_at` and `confirmed_by_user_id` on the
 * payments row — cannot satisfy that. Confirming twice is then an UPDATE, and
 * there is no index in any database that refuses a second UPDATE. The best such
 * a design can do is read the row, see it is already confirmed and decline,
 * which is an application check, and application checks lose races. Two staff
 * members hitting confirm in the same instant both read "not yet confirmed" and
 * both write.
 *
 * Making confirmation an INSERT into a table with a UNIQUE key on payment_id
 * moves the guarantee into the database. The second writer gets a constraint
 * violation no matter how the race falls out, and no matter what any future
 * caller forgets to check.
 *
 * PaymentConfirmationService still locks and checks first, so the ordinary case
 * produces "already confirmed by Mary at 14:32" rather than a raw SQL error.
 * That is courtesy. This index is the guarantee, and the concurrency test
 * proves it by racing two real processes.
 *
 * `branch_id` records where the confirmation happened, taken from the acting
 * staff member rather than from the booking's pickup branch. Spec §12 requires
 * the branch in the audit trail, and the branch that matters is the counter the
 * money was accepted at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_confirmations', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('payment_id')->constrained()->restrictOnDelete();

            // Never null: a confirmation is always somebody's decision. The
            // expiry sweep does not confirm payments, it expires them.
            $table->foreignId('confirmed_by_user_id')->constrained('users')->restrictOnDelete();

            // Null for a Super Admin, who belongs to no counter.
            $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();

            // The figure as confirmed, recorded here as well as on the payment
            // so that the confirmation is a complete record of what was agreed
            // even if the payment row is later corrected.
            $table->decimal('amount_confirmed', 12, 2);

            $table->dateTime('confirmed_at');
            $table->text('notes')->nullable();

            $table->timestamps();

            // THE GUARANTEE. Declared on its own line rather than as a fluent
            // modifier on the column, so that it is impossible to read this
            // table definition without seeing it, and impossible to remove by
            // accident while editing the foreign key. Everything spec §12 says
            // about duplicate confirmation being structurally impossible rests
            // on this one index.
            $table->unique('payment_id');

            $table->index('confirmed_by_user_id');
            $table->index('confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_confirmations');
    }
};
