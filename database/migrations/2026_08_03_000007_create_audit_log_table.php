<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Immutable record of every consequential action.
 *
 * Spec §12 requires that no role may edit or delete an entry, and the
 * developer guideline is blunt about why: if the ORM can edit it, it will
 * eventually be edited. So the guarantee is enforced by the database, not by
 * application discipline — BEFORE UPDATE and BEFORE DELETE triggers raise an
 * error unconditionally.
 *
 * Built now rather than alongside the admin panel because adding append-only
 * triggers to a table that already contains rows is a worse job than creating
 * the table correctly in the first place. Nothing writes to it until there are
 * staff actions worth recording.
 *
 * Deployment note: this requires the TRIGGER privilege. Shared hosting does not
 * always grant it. If the production database refuses these statements, the
 * immutability guarantee degrades to an application-level guard, which is
 * materially weaker than the spec demands — that must be raised before launch,
 * not worked around quietly.
 *
 * There is no `updated_at`. A row that can be updated is not an audit record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $table): void {
            $table->id();

            // Nullable: not every audited action belongs to a booking
            // (enabling a payment method, for example).
            $table->unsignedBigInteger('booking_id')->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();

            $table->string('action');
            $table->string('entity')->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();

            $table->string('status_before')->nullable();
            $table->string('status_after')->nullable();

            $table->decimal('amount', 12, 2)->nullable();
            $table->string('payment_reference')->nullable();

            $table->unsignedBigInteger('branch_id')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();

            // True when a scheduled job took the action rather than a person.
            $table->boolean('is_automatic')->default(false);

            $table->timestamp('created_at')->useCurrent();

            $table->index('booking_id');
            $table->index('actor_user_id');
            $table->index(['entity', 'entity_id']);
            $table->index('action');
            $table->index('created_at');
        });

        // Triggers are MySQL-specific. The suite runs on MySQL precisely so
        // that this guarantee is exercised rather than assumed.
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER audit_log_block_update
            BEFORE UPDATE ON audit_log
            FOR EACH ROW
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'audit_log is append-only: rows cannot be updated.'
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER audit_log_block_delete
            BEFORE DELETE ON audit_log
            FOR EACH ROW
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'audit_log is append-only: rows cannot be deleted.'
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS audit_log_block_update');
            DB::unprepared('DROP TRIGGER IF EXISTS audit_log_block_delete');
        }

        Schema::dropIfExists('audit_log');
    }
};
