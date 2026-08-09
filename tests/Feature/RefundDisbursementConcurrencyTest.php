<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BookingPaymentStatus;
use App\Enums\BookingStatus;
use App\Enums\RefundStatus;
use App\Enums\StaffRole;
use App\Models\AuditLogEntry;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\RefundDisbursement;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * The same money must never be handed back twice. Spec §9.3.
 *
 * The realistic scenario is not a double click. It is two managers working the
 * approvals screen on the same morning — one at the counter about to hand over
 * cash, one at a desk about to send a mobile money transfer — both looking at a
 * refund that reads "approved, not yet paid".
 *
 * This is the fourth of these suites and the most consequential. A double
 * confirmation overstates what a customer paid and can be unpicked from the
 * records. A double disbursement is cash that has left the building.
 *
 * With `disbursed_at` on the refund row, paying twice would be an UPDATE, and no
 * index refuses a second UPDATE — the best available guard would be
 * read-then-write, which every process here would pass before any of them wrote.
 * As an INSERT against a unique key on `refund_disbursements.refund_id`, the
 * database refuses the losers whatever the timing.
 */
final class RefundDisbursementConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    private const COMPETITORS = 4;

    private const BARRIER_LEAD_SECONDS = 5;

    protected function setUp(): void
    {
        parent::setUp();

        // Committed, so the child processes can see them.
        $this->seed(PaymentMethodSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * Hand the next test class a clean database.
     *
     * DatabaseTruncation and RefreshDatabase share RefreshDatabaseState::$migrated.
     * Whichever runs first sets it, after which RefreshDatabase skips
     * migrate:fresh and merely opens a transaction, assuming an empty database.
     * This class truncates before its own tests, never after, and its children
     * commit real rows. Clearing the flag forces the next class to migrate.
     */
    protected function tearDown(): void
    {
        parent::tearDown();

        RefreshDatabaseState::$migrated = false;
    }

    public function test_the_same_refund_cannot_be_disbursed_twice(): void
    {
        [$booking, $refund, $actor] = $this->committedApprovedRefund();

        $results = $this->race($refund, $actor);

        $this->assertNoProcessBroke($results);

        $won = array_filter($results, fn (ProcessResult $r): bool => $r->exitCode() === 0);

        $this->assertCount(
            1,
            $won,
            sprintf(
                'Exactly one disbursement must succeed; %d did. More than one means the same money '
                .'has been handed back more than once.',
                count($won),
            )
        );

        $this->assertSame(
            1,
            RefundDisbursement::query()->where('refund_id', $refund->getKey())->count(),
            'There must be exactly one disbursement row for the refund.',
        );
    }

    /**
     * The consequence that actually costs money.
     *
     * Two disbursements would not merely be an untidy trail. `amount_paid` is
     * confirmed receipts minus disbursed refunds, so subtracting one refund
     * twice would show a customer as having paid K2,150 less than they did — and
     * on a booking that was still live, that reads as an unpaid balance.
     */
    public function test_a_racing_disbursement_never_subtracts_the_refund_twice(): void
    {
        [$booking, $refund, $actor] = $this->committedApprovedRefund();

        $this->assertNoProcessBroke($this->race($refund, $actor));

        $booking->refresh();

        // K2,310 arrived, K2,160 went back once. The K150 fee stays.
        $this->assertSame('150.00', $booking->amount_paid);
        $this->assertSame(BookingPaymentStatus::Refunded, $booking->payment_status);

        $this->assertSame(RefundStatus::Disbursed, $refund->refresh()->status);
    }

    /**
     * Spec §9.3 requires every refund state change to be audited. Four attempts
     * and one payout must therefore leave one entry — a trail claiming the money
     * went back four times is as wrong as a balance that says so.
     */
    public function test_only_the_winning_disbursement_is_audited(): void
    {
        [$booking, $refund, $actor] = $this->committedApprovedRefund();

        $this->assertNoProcessBroke($this->race($refund, $actor));

        $this->assertSame(
            1,
            AuditLogEntry::query()
                ->where('action', 'refund.disbursed')
                ->where('entity_id', $refund->getKey())
                ->count(),
        );
    }

    /**
     * @return array<int, ProcessResult>
     */
    private function race(Refund $refund, User $actor): array
    {
        $barrier = CarbonImmutable::now()->addSeconds(self::BARRIER_LEAD_SECONDS);

        $running = [];

        for ($i = 0; $i < self::COMPETITORS; $i++) {
            // A different reference per process, so that if two ever did get
            // through, the rows would show which attempts they came from.
            $running[$i] = $this->startDisbursementAttempt($refund, $actor, 'MM-'.(4471 + $i), $barrier);
        }

        return $this->settle($running);
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

    private function startDisbursementAttempt(
        Refund $refund,
        User $actor,
        string $reference,
        CarbonImmutable $barrier,
    ): InvokedProcess {
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
                'carhire:attempt-refund-disbursement',
                (string) $refund->getKey(),
                (string) $actor->getKey(),
                $reference,
                '--not-before='.$barrier->format('Y-m-d\TH:i:s.uP'),
            ]);
    }

    /**
     * A cancelled booking holding K2,310, with an approved K2,160 refund, and a
     * manager entitled to pay it out.
     *
     * Committed rather than wrapped in a transaction, so the child processes can
     * see it. The requester and approver are different people, as §9.3 requires
     * and as the table's CHECK constraint enforces.
     *
     * @return array{0: Booking, 1: Refund, 2: User}
     */
    private function committedApprovedRefund(): array
    {
        $booking = Booking::factory()->create([
            'status' => BookingStatus::CancelledByCustomer,
            'payment_status' => BookingPaymentStatus::PaidInFull,
            'grand_total' => '2310.00',
            'booking_deposit_amount' => '1155.00',
            'amount_paid' => '2310.00',
            'balance_due' => '0.00',
            'cancelled_at' => CarbonImmutable::now(),
        ]);

        Payment::factory()->forBooking($booking)->confirmed('2310.00')->create([
            'payment_reference' => $booking->reference.'-1',
            'expected_amount' => '2310.00',
        ]);

        $clerk = User::factory()->withRole(StaffRole::CounterClerk)->create();
        $manager = User::factory()->withRole(StaffRole::BranchManager)->create();

        $refund = Refund::factory()
            ->forBooking($booking)
            ->requestedBy($clerk)
            ->approvedBy($manager)
            ->create();

        return [$booking, $refund, $manager];
    }
}
