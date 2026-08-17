<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\AuditLogTriggers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The append-only guarantee on `audit_log`, and the machinery for restoring it.
 *
 * ⚠ WHAT THIS TEST CANNOT PROVE
 *
 * The interesting path — the database REFUSING to create the triggers for want
 * of the TRIGGER privilege — is not exercised here, and cannot be. Revoking a
 * privilege from the test user requires an administrative grant the test user
 * does not have, and granting it to make the test possible would defeat the
 * point. The local and CI databases both have TRIGGER, which is why
 * `AuditLogImmutabilityTest` has always passed.
 *
 * So this covers the mechanism around the refusal — detection, idempotence, the
 * repair command, the reporting — and the refusal branch itself is reasoned
 * rather than demonstrated. It is written down rather than left for a green tick
 * to imply coverage that is not there, in the same spirit as the note on
 * BookingExpiryServiceTest's race test.
 *
 * The refusal HAS been observed in production: the 20i package for the first
 * deployment lacks the privilege, which is why any of this exists.
 */
final class AuditLogTriggerInstallationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migrating_installs_both_triggers_where_the_privilege_exists(): void
    {
        // RefreshDatabase has already migrated. If the local database has
        // TRIGGER — and it must, or AuditLogImmutabilityTest is meaningless —
        // the migration installed them.
        $this->assertSame([], AuditLogTriggers::missing());
        $this->assertTrue(AuditLogTriggers::allInstalled());
    }

    public function test_installed_reads_the_database_rather_than_assuming(): void
    {
        $installed = AuditLogTriggers::installed();

        $this->assertContains('audit_log_block_update', $installed);
        $this->assertContains('audit_log_block_delete', $installed);
    }

    /**
     * The command runs in production, by hand, possibly more than once. Creating
     * a trigger that already exists is an error in MySQL, so "install only what
     * is missing" is the behaviour, not a nicety.
     */
    public function test_installing_when_already_installed_is_a_no_op(): void
    {
        $this->assertTrue(AuditLogTriggers::install());
        $this->assertTrue(AuditLogTriggers::install());

        $this->assertSame([], AuditLogTriggers::missing());
    }

    public function test_the_command_reports_success_when_protected(): void
    {
        $this->artisan('carhire:install-audit-triggers')
            ->assertExitCode(0);

        $this->artisan('carhire:install-audit-triggers', ['--check' => true])
            ->assertExitCode(0);
    }

    /**
     * THE ONE THAT MATTERS for the deployment this was written for.
     *
     * Drop the triggers to imitate the state a refused migration leaves behind,
     * then prove the repair command restores the guarantee. That is the promise
     * being made to the operator — "this is reversible the day the host grants
     * the privilege" — and it should not rest on an untested code path.
     */
    public function test_the_command_restores_triggers_that_are_missing(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS audit_log_block_update');
        DB::unprepared('DROP TRIGGER IF EXISTS audit_log_block_delete');

        $this->assertCount(2, AuditLogTriggers::missing());

        // --check must report the gap and fail, so a monitor can see it.
        $this->artisan('carhire:install-audit-triggers', ['--check' => true])
            ->assertExitCode(1);

        // And the triggers must still be absent: --check changes nothing.
        $this->assertCount(2, AuditLogTriggers::missing());

        $this->artisan('carhire:install-audit-triggers')
            ->assertExitCode(0);

        $this->assertSame([], AuditLogTriggers::missing());
    }

    /**
     * With the triggers restored, the guarantee must actually hold — proved
     * against raw SQL, bypassing the model guard entirely, because the model
     * guard is the thing this is meant to be stronger than.
     */
    public function test_restored_triggers_still_refuse_a_raw_update(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS audit_log_block_update');
        DB::unprepared('DROP TRIGGER IF EXISTS audit_log_block_delete');

        $this->artisan('carhire:install-audit-triggers')->assertExitCode(0);

        $id = DB::table('audit_log')->insertGetId([
            'action' => 'test.action',
            'is_automatic' => true,
            'created_at' => now(),
        ]);

        $this->expectExceptionMessageMatches('/append-only/');

        DB::table('audit_log')->where('id', $id)->update(['action' => 'tampered']);
    }

    /**
     * The warning text is shared between the migration, the command and the
     * docs so they cannot describe the consequence differently. If somebody
     * softens it, this fails.
     */
    public function test_the_refusal_warning_names_the_consequence_and_the_fix(): void
    {
        $warning = AuditLogTriggers::refusalWarning();

        $this->assertStringContainsString('TRIGGER privilege', $warning);
        // It must say what is weaker, not merely that something failed.
        $this->assertStringContainsString('raw SQL bypasses', $warning);
        // And it must name the way back.
        $this->assertStringContainsString('carhire:install-audit-triggers', $warning);
    }
}
