<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff members belong to a branch and to an operator.
 *
 * WHY BOTH COLUMNS ARE NULLABLE
 *
 * Null is meaningful in each case, and neither means "not filled in yet":
 *
 *  - `branch_id` null means the user is not tied to a counter. A Super Admin
 *    is the obvious case. Spec §12 scopes several actions per branch, and a
 *    user with no branch matches no branch — which is the safe reading, not a
 *    permissive one.
 *  - `operator_id` null means platform staff rather than an operator's
 *    employee. One operator exists at MVP, but the seam is there from the
 *    start (see ARCHITECTURE.md §8) so that opening the platform to other
 *    operators is not a migration across every table holding live data.
 *
 * WHY RESTRICT ON DELETE
 *
 * Deleting a branch or an operator that still has staff attached is refused
 * rather than quietly nulling the column. A user whose `operator_id` silently
 * became null would stop being one operator's employee and start being
 * platform staff, which is a privilege change nobody asked for. It is also
 * consistent with the rest of the schema, where these two foreign keys are
 * restricted everywhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('operator_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->restrictOnDelete();

            $table->foreignId('branch_id')
                ->nullable()
                ->after('operator_id')
                ->constrained()
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('branch_id');
            $table->dropConstrainedForeignId('operator_id');
        });
    }
};
