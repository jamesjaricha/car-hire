<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\VehicleHoldServiceContract;
use App\DataTransferObjects\DateRange;
use App\Exceptions\VehicleNotAvailableException;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Attempts a single vehicle hold and reports success or failure by exit code.
 *
 * This exists for one reason: proving that concurrent hold attempts cannot both
 * succeed. Genuine concurrency needs genuine separate processes competing for
 * the same database row — simulating it inside one PHP process would only test
 * the simulation. The concurrency test launches several copies of this command
 * simultaneously and asserts that exactly one wins.
 *
 * Exit codes: 0 — hold placed. 1 — vehicle unavailable (the expected losing
 * outcome). 2 — anything else, which means the test found a real fault.
 *
 * Refuses to run in production. It writes holds, and nothing outside a test has
 * any business calling it.
 */
final class AttemptVehicleHoldCommand extends Command
{
    protected $signature = 'carhire:attempt-hold
                            {vehicle : Vehicle ID}
                            {start : Hire start, ISO-8601 UTC}
                            {end : Hire end, ISO-8601 UTC}
                            {--expires-in-hours=24 : Hours until the hold lapses}
                            {--not-before= : Wait until this instant before attempting, so several processes collide}';

    protected $description = 'Attempt to place a vehicle hold (test harness for concurrency).';

    public function handle(VehicleHoldServiceContract $holds): int
    {
        if ($this->getLaravel()->isProduction()) {
            $this->error('This command is a test harness and will not run in production.');

            return 2;
        }

        $vehicle = Vehicle::query()->find((int) $this->argument('vehicle'));

        if (! $vehicle instanceof Vehicle) {
            $this->error('Vehicle not found.');

            return 2;
        }

        // Every competing process finishes booting, then waits here for a shared
        // instant. Without this the processes start milliseconds apart and each
        // spends a few hundred milliseconds booting Laravel, so the first one
        // can be finished before the last one has connected — and the test
        // would pass without any contention ever occurring.
        $this->waitForBarrier();

        try {
            $range = DateRange::of(
                (string) $this->argument('start'),
                (string) $this->argument('end'),
            );

            $hold = $holds->place(
                $vehicle,
                $range,
                CarbonImmutable::now()->addHours((int) $this->option('expires-in-hours')),
            );
        } catch (VehicleNotAvailableException $e) {
            $this->line('UNAVAILABLE: '.$e->getMessage());

            return 1;
        } catch (\Throwable $e) {
            $this->error(get_class($e).': '.$e->getMessage());

            return 2;
        }

        $this->line('HELD: '.$hold->getKey());

        return 0;
    }

    /**
     * Sleep until the instant given by --not-before, if any.
     */
    private function waitForBarrier(): void
    {
        $notBefore = $this->option('not-before');

        if (! is_string($notBefore) || $notBefore === '') {
            return;
        }

        $targetMicros = (float) CarbonImmutable::parse($notBefore)->format('U.u');
        $delayMicros = (int) (($targetMicros - microtime(true)) * 1_000_000);

        if ($delayMicros > 0) {
            usleep($delayMicros);
        }
    }
}
