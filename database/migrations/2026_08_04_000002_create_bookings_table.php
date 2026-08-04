<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A hire.
 *
 * THE SNAPSHOT COLUMNS
 *
 * Everything from `vehicle_registration` down is a copy of how things were when
 * the customer agreed to them. It is duplication, on purpose. Without it, a
 * later rate rise, a vehicle being retired, or a staff reassignment to a
 * different unit would retroactively rewrite what a past booking says the
 * customer agreed to — which is exactly the document you need to be truthful
 * when someone disputes a charge six weeks later.
 *
 * THE TWO DEPOSITS
 *
 * `booking_deposit_amount` is a part-payment of the hire, 50% by default, paid
 * online to secure the booking. `security_deposit_amount` is refundable cash
 * taken at the counter against damage. They are different money with different
 * lifecycles and the specification calls conflating them the single most likely
 * misreading. They are deliberately not adjacent in this table.
 *
 * `payment_deadline_at` is NULLABLE, and null is meaningful: a short-notice
 * booking (pickup under four hours away, spec §8.2) has no deadline and no
 * hold, because the customer simply pays at the counter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table): void {
            $table->id();
            $table->string('reference')->unique();

            $table->foreignId('operator_id')->constrained()->restrictOnDelete();
            // Nullable: a booking exists before it is ever linked to a customer
            // record, and staff merges can move it.
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();

            $table->foreignId('vehicle_id')->constrained()->restrictOnDelete();
            $table->foreignId('vehicle_class_id')->constrained()->restrictOnDelete();

            $table->foreignId('pickup_branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('dropoff_branch_id')->constrained('branches')->restrictOnDelete();

            $table->dateTime('pickup_at');
            $table->dateTime('dropoff_at');

            $table->string('status')->default('pending_payment');

            $table->string('payment_method_code');
            // Null for short-notice bookings — see the note above.
            $table->dateTime('payment_deadline_at')->nullable();
            $table->dateTime('payment_reminder_at')->nullable();
            $table->boolean('is_short_notice')->default(false);

            // --- The quote, as agreed -------------------------------------
            $table->decimal('hire_total', 12, 2);
            $table->decimal('insurance_total', 12, 2)->default(0);
            $table->decimal('extras_total', 12, 2)->default(0);
            $table->decimal('cross_border_total', 12, 2)->default(0);
            $table->decimal('grand_total', 12, 2);

            $table->string('currency', 3)->default('ZMW');

            // --- Part-payment of the hire ---------------------------------
            $table->boolean('pay_in_full')->default(false);
            $table->decimal('booking_deposit_amount', 12, 2)->default(0);
            $table->unsignedInteger('deposit_percentage')->default(50);
            $table->decimal('amount_paid', 12, 2)->default(0);
            $table->decimal('balance_due', 12, 2)->default(0);

            // --- Refundable cash against damage ---------------------------
            $table->decimal('security_deposit_amount', 12, 2)->default(0);
            $table->dateTime('security_deposit_collected_at')->nullable();
            $table->dateTime('security_deposit_returned_at')->nullable();
            $table->decimal('security_deposit_returned_amount', 12, 2)->nullable();

            // What the customer remains liable for on a claim. Spec §10.
            $table->decimal('insurance_excess_amount', 12, 2)->default(0);

            // Spec §11. The workflow arrives later; the data does not move.
            $table->string('cross_border_country', 2)->nullable();

            $table->string('terms_version')->nullable();
            $table->dateTime('terms_accepted_at')->nullable();

            // --- Snapshot of what was agreed ------------------------------
            $table->string('vehicle_registration');
            $table->string('vehicle_make');
            $table->string('vehicle_model');
            $table->string('vehicle_class_name');
            $table->decimal('daily_rate_at_booking', 12, 2);
            $table->unsignedInteger('chargeable_days');

            // --- Lifecycle timestamps -------------------------------------
            $table->dateTime('confirmed_at')->nullable();
            $table->dateTime('vehicle_released_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('pickup_at');
            // Drives the expiry sweep: unpaid bookings past their deadline.
            $table->index(['status', 'payment_deadline_at']);
            $table->index(['vehicle_id', 'pickup_at']);
            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
