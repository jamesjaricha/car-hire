<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\AuditLogTriggers;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Install, or report on, the append-only triggers protecting `audit_log`.
 *
 * NOT a test harness — this runs in production, by hand, and its exit code is
 * meant to be read by a person or a monitor.
 *
 * It exists because the migration that would normally create these triggers is
 * allowed to be refused: shared hosting does not always grant `TRIGGER`, and on
 * the 20i package used for the first deployment it does not. Without a way to
 * add them afterwards, that refusal would be permanent, and "we will fix the
 * audit trail once the host grants the privilege" would have no mechanism behind
 * it.
 *
 * Safe to run repeatedly. It creates only what is missing.
 *
 *     php artisan carhire:install-audit-triggers          # install, or report why not
 *     php artisan carhire:install-audit-triggers --check  # report only, change nothing
 *
 * Exit codes are deliberate: 0 when every trigger is present, 1 when any is
 * missing. That makes `--check` usable from a deploy script or a cron that
 * should shout if the guarantee ever regresses.
 */
final class InstallAuditTriggersCommand extends Command
{
    protected $signature = 'carhire:install-audit-triggers
                            {--check : Report the current state without attempting to install}';

    protected $description = 'Install the append-only triggers on audit_log, or report why they are absent';

    public function handle(): int
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->components->warn(
                'Not a MySQL connection. These triggers are MySQL-specific; nothing to do.'
            );

            return self::SUCCESS;
        }

        $expected = array_keys(AuditLogTriggers::TRIGGERS);
        $missing = AuditLogTriggers::missing();

        if ($missing === []) {
            $this->components->info('audit_log is protected. Both triggers present:');

            foreach ($expected as $name) {
                $this->line("  - {$name}");
            }

            return self::SUCCESS;
        }

        $this->components->warn(count($missing).' of '.count($expected).' trigger(s) missing:');

        foreach ($missing as $name) {
            $this->line("  - {$name}");
        }

        if ($this->option('check')) {
            $this->newLine();
            $this->components->error('audit_log is NOT protected at the database level.');
            $this->line(AuditLogTriggers::refusalWarning());

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->task('Installing', static fn (): bool => AuditLogTriggers::install());

        if (AuditLogTriggers::allInstalled()) {
            $this->components->info('audit_log is now protected at the database level.');
            $this->line('Spec section 12 is satisfied again. Record this in docs/OPEN-ITEMS.md.');

            return self::SUCCESS;
        }

        // Deliberately not a thrown exception. The caller is a person on a
        // production shell who needs a sentence and a next action, not a stack
        // trace pointing into the query builder.
        $this->newLine();
        $this->components->error('Refused by the database.');
        $this->line(AuditLogTriggers::refusalWarning());
        $this->newLine();
        $this->line('Ask the host to grant TRIGGER, then run this command again:');
        $this->line('  GRANT TRIGGER ON `<database>`.* TO `<user>`@`%`;');

        return self::FAILURE;
    }
}
