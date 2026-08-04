<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\Vehicle;
use App\Models\VehicleClass;
use App\Models\VehicleHold;
use Carbon\CarbonImmutable;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * The hold concurrency test one level up.
 *
 * That one proves a vehicle cannot be double-held. This proves the whole
 * checkout behaves under contention: the hold, the reference counter, the
 * customer record and the booking row all have to agree, and they are written
 * by different services holding different locks.
 *
 * Two properties are at stake, and they pull in opposite directions:
 *
 *  - Competing for the SAME vehicle, exactly one customer may win.
 *  - Booking DIFFERENT vehicles at the same instant, everyone must win, and
 *    every reference must be distinct.
 *
 * A design that only satisfied the first could simply refuse everything under
 * contention, which is why the second test exists.
 */
final class BookingConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    private const COMPETITORS = 5;

    private const BARRIER_LEAD_SECONDS = 5;

    protected function setUp(): void
    {
        parent::setUp();

        // Committed, so the child processes can see them.
        $this->seed(PaymentMethodSeeder::class);
    }

    /**
     * Hand the next test class a clean database.
     *
     * DatabaseTruncation and RefreshDatabase share one static flag,
     * RefreshDatabaseState::$migrated. Whichever runs first sets it, after
     * which RefreshDatabase skips migrate:fresh and merely opens a
     * transaction — assuming the database it inherits is empty.
     *
     * That assumption does not hold here. This class truncates BEFORE each of
     * its own tests, not after, and its child processes commit real rows in
     * their own connections. Everything downstream would otherwise start with
     * bookings, customers and a booking reference counter already in place.
     *
     * Clearing the flag forces the next class to migrate afresh. It costs one
     * extra migration per concurrency class and removes the coupling entirely.
     */
    protected function tearDown(): void
    {
        parent::tearDown();

        RefreshDatabaseState::$migrated = false;
    }

    public function test_only_one_customer_can_book_the_same_vehicle(): void
    {
        $vehicle = $this->committedVehicle();
        $barrier = CarbonImmutable::now()->addSeconds(self::BARRIER_LEAD_SECONDS);

        $running = [];
        for ($i = 0; $i < self::COMPETITORS; $i++) {
            $running[$i] = $this->startBookingAttempt(
                vehicle: $vehicle,
                barrier: $barrier,
                email: "racer{$i}@example.com",
            );
        }

        $results = $this->settle($running);

        $this->assertNoProcessBroke($results);

        $won = array_filter($results, fn (ProcessResult $r): bool => $r->exitCode() === 0);

        $this->assertCount(
            1,
            $won,
            sprintf('Exactly one booking must succeed; %d did.', count($won))
        );

        $this->assertSame(1, Booking::query()->count());
        $this->assertSame(
            1,
            VehicleHold::query()->where('vehicle_id', $vehicle->getKey())->whereNull('released_at')->count(),
        );
    }

    /**
     * The test that proves the LOCK rather than the unique index.
     *
     * The index on (vehicle_id, start_at, end_at, is_active) catches two holds
     * over identical dates. It cannot catch two holds over dates that merely
     * overlap — different start_at values are different index entries — so this
     * is the case where nothing but correct locking stands between the platform
     * and handing one car to two customers.
     *
     * It is also the case that exposed the original bug. Under REPEATABLE READ a
     * plain SELECT after acquiring the vehicle lock still reads the transaction's
     * original snapshot, so the losing processes saw no conflict. With identical
     * dates the index stopped them; with overlapping dates nothing would have.
     */
    public function test_partially_overlapping_bookings_cannot_both_succeed(): void
    {
        $vehicle = $this->committedVehicle(bufferMinutes: 120);
        $barrier = CarbonImmutable::now()->addSeconds(self::BARRIER_LEAD_SECONDS);
        $base = CarbonImmutable::now()->addDays(10)->setTime(9, 0);

        // Three staggered windows. Every pair overlaps, but no two share a
        // start_at, so the unique index is no help here.
        $windows = [
            [$base, $base->addDays(3)],
            [$base->addDay(), $base->addDays(4)],
            [$base->addDays(2), $base->addDays(5)],
        ];

        $running = [];
        foreach ($windows as $i => [$start, $end]) {
            $running[$i] = $this->startBookingAttempt(
                vehicle: $vehicle,
                barrier: $barrier,
                email: "overlap{$i}@example.com",
                start: $start,
                end: $end,
            );
        }

        $results = $this->settle($running);

        $this->assertNoProcessBroke($results);

        $won = array_filter($results, fn (ProcessResult $r): bool => $r->exitCode() === 0);

        $this->assertCount(
            1,
            $won,
            sprintf(
                'Overlapping windows on one vehicle must yield exactly one booking; %d succeeded. '
                .'More than one means a car has been sold twice.',
                count($won),
            )
        );

        $this->assertSame(1, Booking::query()->count());
        $this->assertSame(
            1,
            VehicleHold::query()
                ->where('vehicle_id', $vehicle->getKey())
                ->whereNull('released_at')
                ->count(),
        );
    }

    /**
     * The requirement flagged when the reference generator was built: uniqueness
     * has to hold when several checkouts land in the same instant. The counter
     * lock is the same mechanism the hold uses, but "the lock generalises" is an
     * assumption, and this is the test that stops it being one.
     */
    public function test_simultaneous_bookings_take_distinct_references(): void
    {
        $vehicles = $this->committedFleet(self::COMPETITORS);
        $barrier = CarbonImmutable::now()->addSeconds(self::BARRIER_LEAD_SECONDS);

        $running = [];
        foreach ($vehicles as $i => $vehicle) {
            $running[$i] = $this->startBookingAttempt(
                vehicle: $vehicle,
                barrier: $barrier,
                email: "buyer{$i}@example.com",
            );
        }

        $results = $this->settle($running);

        $this->assertNoProcessBroke($results);

        // Different vehicles: nobody should have been turned away.
        foreach ($results as $index => $result) {
            $this->assertSame(
                0,
                $result->exitCode(),
                "Booking {$index} was refused although it wanted its own vehicle: "
                .trim($result->output().' '.$result->errorOutput())
            );
        }

        $references = Booking::query()->pluck('reference');

        $this->assertCount(self::COMPETITORS, $references);
        $this->assertSame(
            self::COMPETITORS,
            $references->unique()->count(),
            'Two bookings were issued the same reference: '.$references->implode(', ')
        );

        // And the sequence has no holes, which is the property the counter lock
        // is paid for.
        $this->assertEqualsCanonicalizing(
            ['BR-00001', 'BR-00002', 'BR-00003', 'BR-00004', 'BR-00005'],
            $references->all(),
        );
    }

    /**
     * @param  array<int, InvokedProcess>  $running
     * @return array<int, ProcessResult>
     */
    private function settle(array $running): array
    {
        $results = [];

        foreach ($running as $index => $process) {
            $results[$index] = $process->wait();
        }

        return $results;
    }

    /**
     * @param  array<int, ProcessResult>  $results
     */
    private function assertNoProcessBroke(array $results): void
    {
        $broke = array_filter(
            $results,
            fn (ProcessResult $r): bool => ! in_array($r->exitCode(), [0, 1], true),
        );

        $this->assertCount(
            0,
            $broke,
            'A process failed unexpectedly rather than being refused cleanly: '
            .implode(' | ', array_map(
                fn (ProcessResult $r): string => trim($r->output().' '.$r->errorOutput()),
                $broke,
            ))
        );
    }

    private function startBookingAttempt(
        Vehicle $vehicle,
        CarbonImmutable $barrier,
        string $email,
        ?CarbonImmutable $start = null,
        ?CarbonImmutable $end = null,
    ): InvokedProcess {
        $start ??= CarbonImmutable::now()->addDays(10)->setTime(9, 0);
        $end ??= $start->addDays(3);

        return Process::path(base_path())
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
                'carhire:attempt-booking',
                (string) $vehicle->getKey(),
                $start->toIso8601String(),
                $end->toIso8601String(),
                '--email='.$email,
                '--not-before='.$barrier->format('Y-m-d\TH:i:s.uP'),
            ]);
    }

    private function committedVehicle(int $bufferMinutes = 120): Vehicle
    {
        $class = VehicleClass::factory()->create([
            'turnaround_buffer_minutes' => $bufferMinutes,
        ]);
        $branch = Branch::factory()->create(['operator_id' => $class->operator_id]);

        return Vehicle::factory()->create([
            'operator_id' => $class->operator_id,
            'vehicle_class_id' => $class->getKey(),
            'branch_id' => $branch->getKey(),
        ]);
    }

    /**
     * @return list<Vehicle>
     */
    private function committedFleet(int $count): array
    {
        $class = VehicleClass::factory()->create();
        $branch = Branch::factory()->create(['operator_id' => $class->operator_id]);

        $fleet = [];
        for ($i = 0; $i < $count; $i++) {
            $fleet[] = Vehicle::factory()->create([
                'operator_id' => $class->operator_id,
                'vehicle_class_id' => $class->getKey(),
                'branch_id' => $branch->getKey(),
            ]);
        }

        return $fleet;
    }
}
