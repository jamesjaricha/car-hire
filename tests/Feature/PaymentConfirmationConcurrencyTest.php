<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BookingPaymentStatus;
use App\Enums\BookingStatus;
use App\Enums\PaymentMethodCode;
use App\Enums\PaymentStatus;
use App\Enums\StaffRole;
use App\Models\AuditLogEntry;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\PaymentConfirmation;
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
 * The same money must never be counted twice.
 *
 * The developer guideline names this failure directly: "A double-clicked
 * confirm button must not confirm twice." In practice it is two staff members
 * working the same payment from different screens on a busy morning, which is
 * the same race with more time to go wrong in.
 *
 * This is the test that proves the design decision the phase was built around.
 * With `confirmed_at` on the payment row, confirming twice would be an UPDATE,
 * and no index refuses a second UPDATE — the best available guard would be
 * read-then-write, which both processes here would pass before either wrote.
 * As an INSERT against a unique key on `payment_confirmations.payment_id`, the
 * database refuses the loser whatever the timing.
 *
 * Both previous phases hid a bug that only appeared under genuine contention
 * and that the single-process suite stayed green through. This is the same
 * instrument pointed at the money.
 */
final class PaymentConfirmationConcurrencyTest extends TestCase
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

    public function test_the_same_payment_cannot_be_confirmed_twice(): void
    {
        [$booking, $payment, $actor] = $this->committedBookingAwaitingPayment();

        $barrier = CarbonImmutable::now()->addSeconds(self::BARRIER_LEAD_SECONDS);

        $running = [];
        for ($i = 0; $i < self::COMPETITORS; $i++) {
            $running[$i] = $this->startConfirmationAttempt($payment, $actor, '1155.00', $barrier);
        }

        $results = $this->settle($running);

        $this->assertNoProcessBroke($results);

        $won = array_filter($results, fn (ProcessResult $r): bool => $r->exitCode() === 0);

        $this->assertCount(
            1,
            $won,
            sprintf(
                'Exactly one confirmation must succeed; %d did. More than one means the '
                .'same money has been counted more than once.',
                count($won),
            )
        );

        $this->assertSame(
            1,
            PaymentConfirmation::query()->where('payment_id', $payment->getKey())->count(),
            'There must be exactly one confirmation row for the payment.',
        );
    }

    /**
     * The consequence that actually costs money.
     *
     * Two confirmations would not merely be an untidy audit trail — the balance
     * is recomputed from confirmed receipts, so counting one twice tells a
     * customer they have paid what they have not, and a car can be released
     * against it.
     */
    public function test_a_racing_confirmation_never_doubles_the_amount_paid(): void
    {
        [$booking, $payment, $actor] = $this->committedBookingAwaitingPayment();

        $barrier = CarbonImmutable::now()->addSeconds(self::BARRIER_LEAD_SECONDS);

        $running = [];
        for ($i = 0; $i < self::COMPETITORS; $i++) {
            $running[$i] = $this->startConfirmationAttempt($payment, $actor, '1155.00', $barrier);
        }

        $this->assertNoProcessBroke($this->settle($running));

        $booking->refresh();

        $this->assertSame('1155.00', $booking->amount_paid);
        $this->assertSame('1155.00', $booking->balance_due);
        $this->assertSame(BookingPaymentStatus::PartiallyPaid, $booking->payment_status);

        // The deposit was met, so the booking confirms — once.
        $this->assertSame(BookingStatus::Confirmed, $booking->status);

        $this->assertSame(PaymentStatus::Confirmed, $payment->refresh()->status);
        $this->assertSame('1155.00', $payment->amount);
    }

    /**
     * Spec §12 requires an audit entry for every payment confirmation. It
     * follows that four attempts and one confirmation must leave one entry —
     * an audit trail claiming the money was confirmed four times is as wrong as
     * a balance that says so.
     */
    public function test_only_the_winning_confirmation_is_audited(): void
    {
        [$booking, $payment, $actor] = $this->committedBookingAwaitingPayment();

        $barrier = CarbonImmutable::now()->addSeconds(self::BARRIER_LEAD_SECONDS);

        $running = [];
        for ($i = 0; $i < self::COMPETITORS; $i++) {
            $running[$i] = $this->startConfirmationAttempt($payment, $actor, '1155.00', $barrier);
        }

        $this->assertNoProcessBroke($this->settle($running));

        $this->assertSame(
            1,
            AuditLogEntry::query()
                ->where('action', 'payment.confirmed')
                ->where('booking_id', $booking->getKey())
                ->count(),
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

    private function startConfirmationAttempt(
        Payment $payment,
        User $actor,
        string $amount,
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
                'carhire:attempt-payment-confirmation',
                (string) $payment->getKey(),
                (string) $actor->getKey(),
                $amount,
                '--not-before='.$barrier->format('Y-m-d\TH:i:s.uP'),
            ]);
    }

    /**
     * A committed booking with a deposit receipt waiting on it, and a manager
     * entitled to confirm a bank transfer.
     *
     * @return array{0: Booking, 1: Payment, 2: User}
     */
    private function committedBookingAwaitingPayment(): array
    {
        $booking = Booking::factory()->create([
            'status' => BookingStatus::PendingPayment,
            'payment_status' => BookingPaymentStatus::AwaitingPayment,
            'pay_in_full' => false,
            'grand_total' => '2310.00',
            'booking_deposit_amount' => '1155.00',
            'amount_paid' => '0.00',
            'balance_due' => '2310.00',
            'payment_method_code' => PaymentMethodCode::BankTransfer,
        ]);

        $payment = Payment::factory()->forBooking($booking)->deposit()->create([
            'payment_method_code' => PaymentMethodCode::BankTransfer,
            'status' => PaymentStatus::AwaitingPayment,
            'amount' => '0.00',
            'payment_reference' => $booking->reference.'-1',
        ]);

        $actor = User::factory()->withRole(StaffRole::BranchManager)->create();

        return [$booking, $payment, $actor];
    }
}
