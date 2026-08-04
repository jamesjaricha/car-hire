<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\WaitsForBarrier;
use App\Contracts\BookingCreationServiceContract;
use App\DataTransferObjects\BookingRequest;
use App\DataTransferObjects\CustomerDetails;
use App\DataTransferObjects\DateRange;
use App\Exceptions\BookingNotPossibleException;
use App\Exceptions\PaymentMethodNotAvailableException;
use App\Exceptions\VehicleNotAvailableException;
use App\Models\Branch;
use App\Models\Vehicle;
use Illuminate\Console\Command;
use Throwable;

/**
 * Attempts one whole booking and reports the outcome as an exit code.
 *
 * A companion to carhire:attempt-hold, one level up: that one proves a vehicle
 * cannot be double-held, this one proves the entire checkout — hold, reference,
 * customer and booking row — behaves under genuine contention.
 *
 * Exit codes: 0 — booked, and the reference is printed. 1 — refused for an
 * expected reason (vehicle taken, method unavailable, request malformed).
 * 2 — anything else, meaning the test has found a real fault.
 *
 * Refuses to run in production. It creates bookings.
 */
final class AttemptBookingCommand extends Command
{
    use WaitsForBarrier;

    protected $signature = 'carhire:attempt-booking
                            {vehicle : Vehicle ID}
                            {start : Pickup, ISO-8601 UTC}
                            {end : Dropoff, ISO-8601 UTC}
                            {--email= : Customer email, so competing processes differ}
                            {--method=bank_transfer : Payment method code}
                            {--not-before= : Wait until this instant, so processes collide}';

    protected $description = 'Attempt a booking (test harness for concurrency).';

    public function handle(BookingCreationServiceContract $bookings): int
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

        $branch = Branch::query()->find($vehicle->branch_id);

        if (! $branch instanceof Branch) {
            $this->error('Branch not found.');

            return 2;
        }

        $this->waitForBarrier($this->option('not-before'));

        try {
            $result = $bookings->create(new BookingRequest(
                vehicle: $vehicle,
                range: DateRange::of(
                    (string) $this->argument('start'),
                    (string) $this->argument('end'),
                ),
                pickupBranch: $branch,
                dropoffBranch: $branch,
                customer: new CustomerDetails(
                    fullName: 'Race Tester',
                    email: (string) ($this->option('email') ?: 'race@example.com'),
                    phone: '0977123456',
                ),
                paymentMethodCode: (string) $this->option('method'),
                payInFull: false,
                termsVersion: 'test',
            ));
        } catch (VehicleNotAvailableException|PaymentMethodNotAvailableException|BookingNotPossibleException $e) {
            $this->line('REFUSED: '.$e->getMessage());

            return 1;
        } catch (Throwable $e) {
            $this->error(get_class($e).': '.$e->getMessage());

            return 2;
        }

        $this->line('BOOKED: '.$result->booking->reference);

        return 0;
    }
}
