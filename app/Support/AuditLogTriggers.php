<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The append-only enforcement on `audit_log`, and the only place its SQL lives.
 *
 * ⚠ REFERENCED BY A MIGRATION. Do not rename or delete this class without
 * amending `2026_08_03_000007_create_audit_log_table.php` — a fresh
 * `migrate:fresh` would break.
 *
 * WHY THIS IS A CLASS AND NOT SIX LINES IN THE MIGRATION
 *
 * Spec §12 requires that no role may edit or delete an audit entry, and the
 * developer guideline's reasoning is blunt: if the ORM can edit it, it will
 * eventually be edited. So the guarantee belongs in the database, as two
 * `BEFORE` triggers that raise unconditionally.
 *
 * Shared hosting does not always grant `TRIGGER`. On the 20i package used for
 * the first deployment (2026-08-14) it is absent, and `CREATE TRIGGER` is
 * refused. That left three bad options — fail the deploy, silently ship a
 * weaker guarantee, or duplicate this SQL between a migration and a repair
 * script — and one acceptable one: put the SQL here, let the migration tolerate
 * a refusal loudly, and expose `carhire:install-audit-triggers` so the
 * guarantee can be restored the day the privilege is granted, without touching
 * the data.
 *
 * WHAT IS LOST WHILE THE TRIGGERS ARE ABSENT
 *
 * `AuditLogEntry` also refuses updates and deletes, so the ordinary path is
 * still protected. But that is application discipline, not a guarantee: it
 * holds only for code that goes through the model. Raw SQL, a future model
 * added by somebody who has not read the docblock, or a database client all
 * bypass it. **That is materially weaker than §12 requires** and is recorded in
 * OPEN-ITEMS.md as blocking real launch rather than treated as equivalent.
 */
final class AuditLogTriggers
{
    /**
     * MySQL error numbers that mean "you are not allowed to do this", as
     * opposed to "this statement is wrong".
     *
     * 1142 TRIGGER command denied to user
     * 1227 Access denied; you need (at least one of) the SUPER/TRIGGER privilege
     * 1419 You do not have the SUPER privilege and binary logging is enabled
     *
     * 1419 is included because it is the failure mode on hosts that enable
     * binary logging without setting `log_bin_trust_function_creators` — the
     * privilege looks present and the statement is still refused.
     *
     * @var list<int>
     */
    private const PRIVILEGE_ERRORS = [1142, 1227, 1419];

    /**
     * @var array<string, string>
     */
    public const TRIGGERS = [
        'audit_log_block_update' => <<<'SQL'
            CREATE TRIGGER audit_log_block_update
            BEFORE UPDATE ON audit_log
            FOR EACH ROW
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'audit_log is append-only: rows cannot be updated.'
        SQL,

        'audit_log_block_delete' => <<<'SQL'
            CREATE TRIGGER audit_log_block_delete
            BEFORE DELETE ON audit_log
            FOR EACH ROW
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'audit_log is append-only: rows cannot be deleted.'
        SQL,
    ];

    /**
     * Which of the expected triggers are actually present.
     *
     * Read from `information_schema` rather than remembered, because the whole
     * point of this class is that a migration reporting success is not proof.
     *
     * @return list<string>
     */
    public static function installed(): array
    {
        if (DB::getDriverName() !== 'mysql') {
            return [];
        }

        return DB::table('information_schema.TRIGGERS')
            ->whereRaw('TRIGGER_SCHEMA = DATABASE()')
            ->where('EVENT_OBJECT_TABLE', 'audit_log')
            ->pluck('TRIGGER_NAME')
            ->map(fn (string $name): string => $name)
            ->all();
    }

    /**
     * @return list<string>
     */
    public static function missing(): array
    {
        return array_values(array_diff(array_keys(self::TRIGGERS), self::installed()));
    }

    public static function allInstalled(): bool
    {
        return DB::getDriverName() !== 'mysql' || self::missing() === [];
    }

    /**
     * Create whatever is missing.
     *
     * Returns true when every trigger is present afterwards. Returns FALSE only
     * when the database refused for want of a privilege — anything else throws,
     * because a malformed statement is a bug in this file and must not be
     * mistaken for a hosting limitation.
     */
    public static function install(): bool
    {
        if (DB::getDriverName() !== 'mysql') {
            return true;
        }

        foreach (self::missing() as $name) {
            try {
                DB::unprepared(self::TRIGGERS[$name]);
            } catch (QueryException $e) {
                if (self::isPrivilegeRefusal($e)) {
                    return false;
                }

                throw $e;
            }
        }

        return true;
    }

    private static function isPrivilegeRefusal(QueryException $e): bool
    {
        $driverCode = $e->errorInfo[1] ?? null;

        return is_int($driverCode) && in_array($driverCode, self::PRIVILEGE_ERRORS, true);
    }

    /**
     * The sentence a deploy or a command should print when it could not install
     * them. Kept here so the migration, the command and the docs cannot drift
     * into describing the consequence differently.
     */
    public static function refusalWarning(): string
    {
        return implode(' ', [
            'WARNING: could not create the audit_log append-only triggers —',
            'the database user lacks the TRIGGER privilege.',
            'audit_log immutability is currently enforced only by the AuditLogEntry model,',
            'which raw SQL bypasses. This is weaker than spec section 12 requires.',
            'Grant TRIGGER and run `php artisan carhire:install-audit-triggers` to restore it.',
        ]);
    }
}
