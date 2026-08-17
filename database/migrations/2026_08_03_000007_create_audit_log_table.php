<?php

declare(strict_types=1);

use App\Support\AuditLogTriggers;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
 * Deployment note: this requires the TRIGGER privilege, and shared hosting does
 * not always grant it. On the 20i package used for the first deployment
 * (2026-08-14) it is absent.
 *
 * This migration therefore no longer FAILS when the triggers are refused — it
 * warns, loudly, and continues. That is not the guarantee being quietly
 * downgraded: the refusal is printed on every deploy, recorded in OPEN-ITEMS.md
 * as blocking real launch, and `carhire:install-audit-triggers` restores it the
 * day the privilege is granted, without touching the data.
 *
 * The alternative was worse in both directions. Failing hard left a
 * half-migrated database — `audit_log` created, the migration unrecorded, and a
 * retry erroring on "table already exists" — and made a hosting limitation look
 * like a broken build. Swallowing the error silently would have shipped a weaker
 * audit trail with nothing anywhere saying so.
 *
 * The SQL itself lives in `App\Support\AuditLogTriggers` so that the repair
 * command cannot drift from what the migration creates.
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

        if (AuditLogTriggers::install()) {
            return;
        }

        // Refused for want of a privilege. Say so on stderr as well as in the
        // log: a deploy scrolling past this in a wall of migration output is
        // exactly how a weaker audit trail becomes something nobody knew about.
        Log::warning(AuditLogTriggers::refusalWarning());

        fwrite(STDERR, PHP_EOL.str_repeat('!', 78).PHP_EOL);
        fwrite(STDERR, AuditLogTriggers::refusalWarning().PHP_EOL);
        fwrite(STDERR, str_repeat('!', 78).PHP_EOL.PHP_EOL);
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
