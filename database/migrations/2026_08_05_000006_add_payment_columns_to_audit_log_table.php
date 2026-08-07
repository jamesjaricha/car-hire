<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two fields spec §12 requires on every audit entry that Phase 1 did not
 * anticipate: the payment method, and whether proof had been uploaded.
 *
 * Added as columns rather than folded into the existing `metadata` json,
 * because an auditor's questions are "show me every cash confirmation last
 * week" and "which confirmations had proof attached" — both of which are
 * queries, and neither of which json answers well. "Every entry records X" also
 * stays honest as a column; as a json key it is a convention that one call site
 * can quietly omit.
 *
 * Done now because `audit_log` is still empty. It has existed since Phase 1
 * with nothing writing to it, and AuditLogger — the first writer — arrives in
 * the same commit as this migration. This is the last moment adding columns
 * costs nothing.
 *
 * `proof_uploaded` is nullable on purpose: null means the question does not
 * apply to this action, which is different from false, meaning proof was
 * expected and absent.
 *
 * The BEFORE UPDATE and BEFORE DELETE triggers are untouched. They fire on row
 * changes, not on schema changes, so the append-only guarantee is unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_log', function (Blueprint $table): void {
            $table->string('payment_method_code')->nullable()->after('payment_reference');
            $table->boolean('proof_uploaded')->nullable()->after('payment_method_code');
        });
    }

    public function down(): void
    {
        Schema::table('audit_log', function (Blueprint $table): void {
            $table->dropColumn(['payment_method_code', 'proof_uploaded']);
        });
    }
};
