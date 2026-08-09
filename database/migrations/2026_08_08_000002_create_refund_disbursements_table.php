<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE POINT OF THIS TABLE IS THE UNIQUE KEY ON refund_id.
 *
 * Spec §9.3: "Never allow the same refund to be disbursed twice." That is the
 * same requirement §12 makes of payment confirmation, pointed the other way —
 * and it gets the same answer, for the same reason.
 *
 * A `disbursed_at` column on the refunds row cannot deliver it. Disbursing
 * twice would then be an UPDATE, and no index in any database refuses a second
 * UPDATE. The strongest available guard is to read the row, see it is already
 * disbursed and decline, which is an application check, and application checks
 * lose races: two managers on the approvals screen at 09:00 both read "approved,
 * not yet paid", and both hand over cash.
 *
 * As an INSERT against a unique key, the database refuses the second writer
 * however the race falls out, and regardless of what any future caller forgets
 * to check. `RefundDisbursementService` still locks and checks first, so the
 * ordinary case reads "already paid out by Mary at 14:32" rather than a raw SQL
 * error — courtesy, not the mechanism. `RefundDisbursementConcurrencyTest`
 * proves it with real processes.
 *
 * WHY disbursement_reference IS NOT NULLABLE
 *
 * §9.3 requires proof of disbursement: a signed cash receipt number, a transfer
 * reference, a MoMo transaction ID. A nullable column here would make that
 * requirement optional in practice, and the one refund nobody can evidence is
 * the one that gets queried. If a member of staff has nothing to type, the
 * money has not actually left.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refund_disbursements', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('refund_id')->constrained()->restrictOnDelete();

            // Never null. Money does not leave the business by itself.
            $table->foreignId('disbursed_by_user_id')->constrained('users')->restrictOnDelete();

            // The counter it was handed over at, taken from the acting staff
            // member. Null for a Super Admin, who belongs to no branch.
            $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();

            // Recorded here as well as on the refund, so the disbursement is a
            // complete record of what changed hands even if the refund row is
            // later annotated.
            $table->decimal('amount_disbursed', 12, 2);

            // §9.3's proof. Required — see the header.
            $table->string('disbursement_reference');

            $table->dateTime('disbursed_at');
            $table->text('notes')->nullable();

            $table->timestamps();

            // THE GUARANTEE. On its own line rather than as a fluent modifier
            // on the column above, so that it cannot be read past and cannot be
            // removed by accident while editing the foreign key. Everything
            // §9.3 says about a refund never being disbursed twice rests here.
            $table->unique('refund_id');

            $table->index('disbursed_by_user_id');
            $table->index('disbursed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_disbursements');
    }
};
