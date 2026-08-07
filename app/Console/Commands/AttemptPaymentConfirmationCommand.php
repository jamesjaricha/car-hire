<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\WaitsForBarrier;
use App\Contracts\PaymentConfirmationServiceContract;
use App\Exceptions\PaymentNotConfirmableException;
use App\Exceptions\PaymentNotRecordableException;
use App\Exceptions\StaffPermissionDeniedException;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Console\Command;
use Throwable;

/**
 * Attempts one payment confirmation and reports the outcome as an exit code.
 *
 * The third of the concurrency harnesses. carhire:attempt-hold proves a vehicle
 * cannot be double-held and carhire:attempt-booking proves the whole checkout
 * holds up; this one proves the same money cannot be counted twice.
 *
 * The scenario is the one the developer guideline names directly: a staff
 * member double-clicks confirm, or two of them work the same payment from
 * different screens.
 *
 * Exit codes: 0 — confirmed. 1 — refused for an expected reason, which for this
 * command means somebody else got there first. 2 — anything else, meaning the
 * test has found a real fault.
 *
 * Refuses to run in production. It moves bookings and money.
 */
final class AttemptPaymentConfirmationCommand extends Command
{
    use WaitsForBarrier;

    protected $signature = 'carhire:attempt-payment-confirmation
                            {payment : Payment ID}
                            {user : Confirming staff user ID}
                            {amount : Amount received}
                            {--not-before= : Wait until this instant, so processes collide}';

    protected $description = 'Attempt a payment confirmation (test harness for concurrency).';

    public function handle(PaymentConfirmationServiceContract $confirmations): int
    {
        if ($this->getLaravel()->isProduction()) {
            $this->error('This command is a test harness and will not run in production.');

            return 2;
        }

        $payment = Payment::query()->find((int) $this->argument('payment'));

        if (! $payment instanceof Payment) {
            $this->error('Payment not found.');

            return 2;
        }

        $actor = User::query()->find((int) $this->argument('user'));

        if (! $actor instanceof User) {
            $this->error('User not found.');

            return 2;
        }

        $this->waitForBarrier($this->option('not-before'));

        try {
            $result = $confirmations->confirm(
                actor: $actor,
                payment: $payment,
                amountReceived: (string) $this->argument('amount'),
            );
        } catch (PaymentNotConfirmableException|StaffPermissionDeniedException|PaymentNotRecordableException $e) {
            $this->line('REFUSED: '.$e->getMessage());

            return 1;
        } catch (Throwable $e) {
            $this->error(get_class($e).': '.$e->getMessage());

            return 2;
        }

        $this->line('CONFIRMED: '.$result->payment->payment_reference.' paid='.$result->amountPaid);

        return 0;
    }
}
