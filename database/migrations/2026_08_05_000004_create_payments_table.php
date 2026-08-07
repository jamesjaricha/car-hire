<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One receipt: a single sum of money, expected or arrived.
 *
 * WHY booking_id IS NULLABLE
 *
 * The developer guideline §5 warns that mobile money statements are not
 * real-time and that till-number payments frequently do not carry the reference
 * the customer was given. So money arrives that nobody can yet attribute to a
 * booking. That receipt is real and must be recorded the moment it is seen —
 * an unattributable payment that cannot be written down gets written on paper
 * instead, and then it is not in the system at all.
 *
 * A null booking_id is therefore the unmatched-payments queue, and the guideline
 * is explicit that retrofitting that queue later is painful.
 *
 * WHY expected_amount IS ALSO NULLABLE
 *
 * `amount` is what actually arrived. `expected_amount` is what was due when the
 * receipt was raised — the deposit or the full hire, whichever the customer
 * chose at checkout. The two are compared to flag a shortfall.
 *
 * The booking's `balance_due` cannot serve as that comparison, because it moves
 * as payments are confirmed: the same short payment would look different
 * depending on when the question was asked. But an unmatched receipt has no
 * booking and therefore no expectation, so the column is null until it is
 * matched.
 *
 * WHY THERE IS NO confirmed_at COLUMN HERE
 *
 * Confirmation lives in its own table, one row per payment, with a unique key.
 * Spec §12 requires duplicate confirmation to be "structurally impossible", and
 * confirming twice by UPDATE is something no index can refuse. See the
 * payment_confirmations migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();

            // Null until an unmatched receipt is attributed to a booking.
            $table->foreignId('booking_id')->nullable()->constrained()->restrictOnDelete();

            // The multi-operator seam, as on every other core table. Null for
            // a receipt that has not yet been attributed to anyone's fleet.
            $table->foreignId('operator_id')->nullable()->constrained()->restrictOnDelete();

            // BR-00001-1. Derived from the booking so staff can read it aloud
            // and match it against a bank line.
            $table->string('payment_reference')->unique();

            $table->string('payment_method_code');
            $table->string('status')->default('awaiting_payment');

            // Which option the customer chose at checkout, not whether this
            // receipt happens to be for half the money.
            $table->boolean('is_deposit')->default(false);

            // What arrived. Zero until a staff member records receipt.
            $table->decimal('amount', 12, 2)->default(0);
            // What was due when this was raised. Null for unmatched receipts.
            $table->decimal('expected_amount', 12, 2)->nullable();

            $table->string('currency', 3)->default('ZMW');

            // The reference the PAYER used — a MoMo transaction id, a bank
            // reference. Frequently not ours, which is the whole problem.
            $table->string('external_reference')->nullable();
            $table->text('notes')->nullable();

            // Spec §7.1 proof_submitted. Private disk; the upload UI is Phase 5.
            $table->string('proof_path')->nullable();
            $table->dateTime('proof_uploaded_at')->nullable();

            // Null when the customer's checkout raised this receipt rather than
            // a member of staff keying it in.
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->restrictOnDelete();

            // Set when an unmatched receipt is attributed to a booking.
            $table->foreignId('matched_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('matched_at')->nullable();

            $table->timestamps();

            $table->index('booking_id');
            $table->index('status');
            $table->index('payment_method_code');
            // The unmatched queue: booking_id IS NULL, oldest first.
            $table->index(['booking_id', 'created_at']);
            // Staff searching for the reference a customer quoted them.
            $table->index('external_reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
