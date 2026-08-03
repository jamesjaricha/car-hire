<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\AuditLogImmutableException;
use App\Models\AuditLogEntry;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AuditLogImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_entries_can_be_appended(): void
    {
        $entry = AuditLogEntry::query()->create([
            'action' => 'payment.confirmed',
            'entity' => 'payment',
            'entity_id' => 1,
            'amount' => '1250.00',
            'payment_reference' => 'PR-00001',
            'notes' => 'Confirmed against MoMo statement.',
        ]);

        $this->assertDatabaseHas('audit_log', [
            'id' => $entry->getKey(),
            'action' => 'payment.confirmed',
        ]);
    }

    public function test_updating_through_the_model_is_refused(): void
    {
        $entry = AuditLogEntry::query()->create(['action' => 'kyc.verified']);

        $this->expectException(AuditLogImmutableException::class);

        $entry->update(['action' => 'kyc.rejected']);
    }

    public function test_deleting_through_the_model_is_refused(): void
    {
        $entry = AuditLogEntry::query()->create(['action' => 'refund.approved']);

        $this->expectException(AuditLogImmutableException::class);

        $entry->delete();
    }

    public function test_the_database_refuses_an_update_that_bypasses_the_model(): void
    {
        // The one that actually matters. The model guard is convenience; this
        // proves the guarantee holds even when something writes raw SQL, which
        // is exactly what the developer guideline insists on.
        $entry = AuditLogEntry::query()->create(['action' => 'deadline.extended']);

        $this->expectException(QueryException::class);

        DB::table('audit_log')
            ->where('id', $entry->getKey())
            ->update(['action' => 'tampered']);
    }

    public function test_the_database_refuses_a_delete_that_bypasses_the_model(): void
    {
        $entry = AuditLogEntry::query()->create(['action' => 'vehicle.reassigned']);

        $this->expectException(QueryException::class);

        DB::table('audit_log')->where('id', $entry->getKey())->delete();
    }

    public function test_a_refused_update_leaves_the_row_untouched(): void
    {
        $entry = AuditLogEntry::query()->create(['action' => 'security-deposit.collected']);

        try {
            DB::table('audit_log')->where('id', $entry->getKey())->update(['action' => 'tampered']);
        } catch (QueryException) {
            // Expected.
        }

        $this->assertDatabaseHas('audit_log', [
            'id' => $entry->getKey(),
            'action' => 'security-deposit.collected',
        ]);
    }
}
