<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\BookingReferenceGeneratorContract;
use App\Models\BookingReferenceCounter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class BookingReferenceGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private BookingReferenceGeneratorContract $references;

    protected function setUp(): void
    {
        parent::setUp();

        $this->references = app(BookingReferenceGeneratorContract::class);
    }

    public function test_the_first_reference_starts_the_sequence(): void
    {
        $this->assertSame('BR-00001', $this->references->next());
    }

    public function test_references_are_issued_in_order(): void
    {
        $this->assertSame('BR-00001', $this->references->next());
        $this->assertSame('BR-00002', $this->references->next());
        $this->assertSame('BR-00003', $this->references->next());
    }

    public function test_the_counter_is_created_on_first_use(): void
    {
        $this->assertDatabaseCount('booking_reference_counters', 0);

        $this->references->next();

        $this->assertDatabaseHas('booking_reference_counters', [
            'prefix' => 'BR',
            'next_value' => 2,
        ]);
    }

    public function test_padding_is_a_minimum_not_a_maximum(): void
    {
        // Once the sequence outgrows five digits the reference must render in
        // full rather than being truncated into a collision.
        BookingReferenceCounter::query()->create(['prefix' => 'BR', 'next_value' => 999999]);

        $this->assertSame('BR-999999', $this->references->next());
    }

    public function test_the_prefix_is_configurable(): void
    {
        config(['carhire.booking_reference_prefix' => 'CH']);

        $this->assertSame('CH-00001', $this->references->next());
    }

    public function test_each_prefix_keeps_its_own_sequence(): void
    {
        $this->assertSame('BR-00001', $this->references->next());
        $this->assertSame('BR-00002', $this->references->next());

        config(['carhire.booking_reference_prefix' => 'CH']);

        // Changing the prefix starts a separate sequence rather than
        // continuing or renumbering the existing one.
        $this->assertSame('CH-00001', $this->references->next());
    }

    public function test_a_rolled_back_booking_gives_its_number_back(): void
    {
        // The reference is reserved inside the booking's transaction, so a
        // failed checkout must not burn a number. Gaps in a sequence staff read
        // aloud to customers invite the question "what happened to BR-00042?".
        DB::beginTransaction();
        $reserved = $this->references->next();
        DB::rollBack();

        $this->assertSame($reserved, $this->references->next());
    }

    public function test_a_committed_reference_is_not_reissued(): void
    {
        DB::beginTransaction();
        $first = $this->references->next();
        DB::commit();

        $this->assertNotSame($first, $this->references->next());
    }
}
