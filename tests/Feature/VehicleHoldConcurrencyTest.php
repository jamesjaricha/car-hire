<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Vehicle;
use App\Models\VehicleClass;
use App\Models\VehicleHold;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * The test the whole booking engine rests on.
 *
 * Everything else in the suite runs in a single process, where nothing
 * genuinely competes. Double-booking is a race, and a race can only be
 * demonstrated with real parallel processes contending for the same database
 * row — so this test launches several, holds them at a shared barrier until
 * they have all finished booting, and releases them at the same instant.
 *
 * Without the barrier the test would be near-worthless: each child spends a few
 * hundred milliseconds booting Laravel, so the first could finish before the
 * last had even connected, and the assertion would pass with no contention ever
 * having occurred.
 *
 * DatabaseTruncation rather than RefreshDatabase, deliberately: RefreshDatabase
 * wraps each test in a transaction that is never committed, so the child
 * processes could not see the vehicle this test creates.
 */
final class VehicleHoldConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    private const COMPETITORS = 6;

    private const BARRIER_LEAD_SECONDS = 4;

    /**
     * Hand the next test class a clean database.
     *
     * This class truncates before each of its own tests, not after, and its
     * child processes commit rows on their own connections. Because
     * DatabaseTruncation and RefreshDatabase share the
     * RefreshDatabaseState::$migrated flag, a RefreshDatabase class running
     * afterwards would skip migrate:fresh and inherit those rows.
     *
     * Today this class happens to sort last and so gets away with it. That is
     * a property of the alphabet, not of the design — one new test file could
     * silently break ten others.
     */
    protected function tearDown(): void
    {
        parent::tearDown();

        RefreshDatabaseState::$migrated = false;
    }

    public function test_only_one_of_several_simultaneous_attempts_can_hold_a_vehicle(): void
    {
        $vehicle = $this->committedVehicle();

        $start = CarbonImmutable::parse('2026-09-01T09:00:00Z');
        $end = CarbonImmutable::parse('2026-09-04T09:00:00Z');
        $barrier = CarbonImmutable::now()->addSeconds(self::BARRIER_LEAD_SECONDS);

        $running = [];
        for ($i = 0; $i < self::COMPETITORS; $i++) {
            $running[$i] = $this->startHoldAttempt($vehicle, $start, $end, $barrier);
        }

        /** @var array<int, ProcessResult> $results */
        $results = [];
        foreach ($running as $index => $process) {
            $results[$index] = $process->wait();
        }

        $won = array_filter($results, fn (ProcessResult $r): bool => $r->exitCode() === 0);
        $lost = array_filter($results, fn (ProcessResult $r): bool => $r->exitCode() === 1);
        $broke = array_filter($results, fn (ProcessResult $r): bool => ! in_array($r->exitCode(), [0, 1], true));

        $this->assertCount(
            0,
            $broke,
            'A process failed unexpectedly rather than losing cleanly: '
            .implode(' | ', array_map(
                fn (ProcessResult $r): string => trim($r->output().' '.$r->errorOutput()),
                $broke,
            ))
        );

        $this->assertCount(
            1,
            $won,
            sprintf(
                'Exactly one attempt must succeed. Won: %d, lost: %d, of %d.',
                count($won),
                count($lost),
                self::COMPETITORS,
            )
        );

        $this->assertSame(
            1,
            VehicleHold::query()
                ->where('vehicle_id', $vehicle->getKey())
                ->whereNull('released_at')
                ->count(),
            'The vehicle must carry exactly one live hold.'
        );
    }

    public function test_simultaneous_attempts_on_separate_windows_can_both_succeed(): void
    {
        // The inverse guard. If the lock were over-broad — serialising every
        // hold on a vehicle regardless of dates, or rejecting on contention
        // rather than on genuine overlap — this would wrongly fail, and the
        // fleet would be far less bookable than it should be.
        $vehicle = $this->committedVehicle(bufferMinutes: 120);
        $barrier = CarbonImmutable::now()->addSeconds(self::BARRIER_LEAD_SECONDS);

        $first = $this->startHoldAttempt(
            $vehicle,
            CarbonImmutable::parse('2026-09-01T09:00:00Z'),
            CarbonImmutable::parse('2026-09-04T09:00:00Z'),
            $barrier,
        );

        // Starts a full day after the first ends — well clear of the buffer.
        $second = $this->startHoldAttempt(
            $vehicle,
            CarbonImmutable::parse('2026-09-05T09:00:00Z'),
            CarbonImmutable::parse('2026-09-08T09:00:00Z'),
            $barrier,
        );

        $firstResult = $first->wait();
        $secondResult = $second->wait();

        $this->assertSame(
            0,
            $firstResult->exitCode(),
            'First window failed: '.trim($firstResult->output().' '.$firstResult->errorOutput())
        );
        $this->assertSame(
            0,
            $secondResult->exitCode(),
            'Second window failed: '.trim($secondResult->output().' '.$secondResult->errorOutput())
        );

        $this->assertSame(2, VehicleHold::query()->where('vehicle_id', $vehicle->getKey())->count());
    }

    private function startHoldAttempt(
        Vehicle $vehicle,
        CarbonImmutable $start,
        CarbonImmutable $end,
        CarbonImmutable $barrier,
    ): InvokedProcess {
        return Process::path(base_path())
            // Laravel's dotenv loader does not overwrite variables already
            // present in the real environment, so these win over .env and keep
            // the child processes on the test database rather than the working
            // one. If that ever stopped holding, this test would find zero
            // holds and fail loudly rather than corrupting dev data silently.
            ->env([
                'APP_ENV' => 'testing',
                'DB_CONNECTION' => 'mysql',
                'DB_DATABASE' => config('database.connections.mysql.database'),
                'CACHE_STORE' => 'array',
                'QUEUE_CONNECTION' => 'sync',
                'SESSION_DRIVER' => 'array',
            ])
            ->timeout(60)
            ->start([
                PHP_BINARY,
                'artisan',
                'carhire:attempt-hold',
                (string) $vehicle->getKey(),
                $start->toIso8601String(),
                $end->toIso8601String(),
                '--not-before='.$barrier->format('Y-m-d\TH:i:s.uP'),
            ]);
    }

    /**
     * A vehicle that is genuinely committed, so other processes can see it.
     */
    private function committedVehicle(int $bufferMinutes = 120): Vehicle
    {
        $class = VehicleClass::factory()->create(['turnaround_buffer_minutes' => $bufferMinutes]);

        return Vehicle::factory()->create([
            'operator_id' => $class->operator_id,
            'vehicle_class_id' => $class->getKey(),
        ]);
    }
}
