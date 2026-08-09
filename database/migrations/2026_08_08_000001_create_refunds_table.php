<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Money going back to a customer. Spec §9.
 *
 * REFUNDS ARE THEIR OWN LEDGER, NOT A REVERSAL OF THE PAYMENT
 *
 * The tempting design flips the original receipt to `refunded` and calls it
 * done. It is wrong, and quietly: a receipt records that money genuinely
 * arrived, on a date, confirmed by a named person against a bank line. That
 * happened. Rewriting the row to say otherwise destroys the only record of an
 * event that is still true, and leaves the operator unable to reconcile the
 * month it fell in.
 *
 * So `amount_paid` becomes SUM(confirmed receipts) − SUM(disbursed refunds),
 * and payment rows keep their status forever. `BookingLedger` is the one place
 * that arithmetic lives.
 *
 * WHY THE FIGURES ARE FROZEN HERE
 *
 * `amount_paid_at_request`, `booking_deposit_retained`, `admin_fee_deducted`
 * and `amount` are all snapshots taken when the refund was raised, for exactly
 * the reason `payments.expected_amount` is: the underlying values move. The
 * admin fee is a setting the operator can change this afternoon; `amount_paid`
 * changes the moment another receipt is confirmed. A refund recomputed at
 * approval time would be a different number from the one the requester saw and
 * the customer was told, with nothing to show which was meant.
 *
 * Recomputing on read also makes the §9.1 timing rule unstable — whether a
 * cancellation was inside 24 hours of pickup is a fact about the moment it was
 * requested, and it stops being true a day later.
 *
 * THE TWO-PERSON RULE IS A DATABASE CONSTRAINT
 *
 * Spec §9.3 requires "a separate role to approve refunds from the one that
 * requests them". `RefundRequestService::approve()` enforces it, and so does
 * the CHECK constraint at the bottom of this file. That duplication is
 * deliberate — this is a fraud control, not merely a correctness rule, and a
 * fraud control that lives only in application code is one careless service
 * method away from being absent. Same argument as the unique key on
 * `payment_confirmations`: where the database can hold the rule, it should.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('booking_id')->constrained()->restrictOnDelete();

            // The multi-operator seam, as on every other core table.
            $table->foreignId('operator_id')->nullable()->constrained()->restrictOnDelete();

            // Which §9 rule produced the figures below. Drives the calculation
            // and cannot be edited afterwards without recomputing them.
            $table->string('reason');
            $table->string('status')->default('requested');

            // How the money goes back — cash at the counter, a bank transfer,
            // a mobile money send. Not necessarily how it arrived: a customer
            // who paid by MoMo may be refunded in cash at the desk.
            $table->string('method');

            // --- Frozen at request. See the header. --------------------------

            $table->decimal('amount_paid_at_request', 12, 2);

            // Spec §9.1: within 24 hours of pickup the booking deposit is
            // non-refundable. Recorded separately from the fee because it is a
            // different thing being withheld for a different reason, and the
            // customer who rings up to ask will be told both.
            $table->decimal('booking_deposit_retained', 12, 2)->default(0);

            // The fee as configured when the refund was raised, before
            // clamping. Stored alongside the applied figure so that a refund
            // which could only absorb part of the fee explains itself — "the
            // fee is K150 but only K100 remained" is a question staff will be
            // asked, and subtracting two stored columns is a poor way to have
            // to answer it.
            $table->decimal('admin_fee_configured', 12, 2)->default(0);

            // The fee AS APPLIED. Clamped: a customer who paid less than the
            // fee cannot be charged more than they paid, and the row should say
            // what was actually deducted.
            $table->decimal('admin_fee_deducted', 12, 2)->default(0);

            // What the customer gets. Computed, locked, never typed by staff.
            $table->decimal('amount', 12, 2);

            // True when the fee above was drawn from a §15.1 placeholder rather
            // than a figure the business has decided. Frozen with the rest so
            // that a refund raised today is still readable as "computed with an
            // undecided fee" after the operator enters a real one. Without it,
            // every historic zero-fee refund would silently look deliberate.
            $table->boolean('admin_fee_was_placeholder')->default(false);

            $table->string('currency', 3)->default('ZMW');

            // --- Who, and when ----------------------------------------------

            $table->foreignId('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->dateTime('requested_at');

            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('approved_at')->nullable();

            // Rejection is somebody's decision too, and §12 wants the person
            // recorded for every state change.
            $table->foreignId('rejected_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index('booking_id');
            $table->index('status');
            // The approval queue: status = requested, oldest first.
            $table->index(['status', 'requested_at']);
        });

        // Laravel's Blueprint has no check() helper, so this is raw SQL.
        //
        // Written to pass when approved_by_user_id IS NULL rather than relying
        // on MySQL treating a NULL comparison as satisfied. The behaviour is
        // the same; being explicit means a reader does not have to know that
        // rule to see that an unapproved refund is legal.
        DB::statement(
            'ALTER TABLE refunds ADD CONSTRAINT refunds_approver_differs_from_requester '
            .'CHECK (approved_by_user_id IS NULL OR approved_by_user_id <> requested_by_user_id)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
