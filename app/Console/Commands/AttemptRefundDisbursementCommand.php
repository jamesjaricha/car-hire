<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\WaitsForBarrier;
use App\Contracts\RefundDisbursementServiceContract;
use App\Exceptions\RefundNotDisbursableException;
use App\Exceptions\StaffPermissionDeniedException;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Console\Command;
use Throwable;

/**
 * Attempts one refund disbursement and reports the outcome as an exit code.
 *
 * The fourth concurrency harness. `carhire:attempt-hold` proves a vehicle cannot
 * be double-held, `carhire:attempt-booking` proves the whole checkout holds up,
 * `carhire:attempt-payment-confirmation` proves the same money cannot be counted
 * twice — and this one proves the same money cannot be handed back twice.
 *
 * It is the most expensive of the four to get wrong. A duplicated confirmation
 * overstates what a customer paid, which is recoverable from the records. A
 * duplicated disbursement is cash that has physically left the building.
 *
 * Exit codes: 0 — disbursed. 1 — refused for an expected reason, which here
 * means somebody else got there first. 2 — anything else, meaning the test has
 * found a real fault.
 *
 * Refuses to run in production. It moves money.
 */
final class AttemptRefundDisbursementCommand extends Command
{
    use WaitsForBarrier;

    protected $signature = 'carhire:attempt-refund-disbursement
                            {refund : Refund ID}
                            {user : Disbursing staff user ID}
                            {reference : Disbursement reference}
                            {--not-before= : Wait until this instant, so processes collide}';

    protected $description = 'Attempt a refund disbursement (test harness for concurrency).';

    public function handle(RefundDisbursementServiceContract $disbursements): int
    {
        if ($this->getLaravel()->isProduction()) {
            $this->error('This command is a test harness and will not run in production.');

            return 2;
        }

        $refund = Refund::query()->find((int) $this->argument('refund'));

        if (! $refund instanceof Refund) {
            $this->error('Refund not found.');

            return 2;
        }

        $actor = User::query()->find((int) $this->argument('user'));

        if (! $actor instanceof User) {
            $this->error('User not found.');

            return 2;
        }

        $this->waitForBarrier($this->option('not-before'));

        try {
            $result = $disbursements->disburse(
                actor: $actor,
                refund: $refund,
                disbursementReference: (string) $this->argument('reference'),
            );
        } catch (RefundNotDisbursableException|StaffPermissionDeniedException $e) {
            $this->line('REFUSED: '.$e->getMessage());

            return 1;
        } catch (Throwable $e) {
            $this->error(get_class($e).': '.$e->getMessage());

            return 2;
        }

        $this->line(sprintf(
            'DISBURSED: refund #%d paid %s, booking now paid %s',
            $result->refund->getKey(),
            $result->disbursement->amount_disbursed,
            $result->amountPaid,
        ));

        return 0;
    }
}
