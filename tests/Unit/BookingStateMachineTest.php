<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DataTransferObjects\TransitionContext;
use App\Enums\BookingStatus;
use App\Enums\TransitionActor;
use App\Exceptions\InvalidBookingTransitionException;
use App\Services\Bookings\BookingStateMachine;
use Tests\TestCase;

final class BookingStateMachineTest extends TestCase
{
    private BookingStateMachine $machine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->machine = new BookingStateMachine;
    }

    /**
     * The whole of spec §7.3, transcribed here independently of the
     * implementation. If someone edits the transition map, this test says so.
     */
    public function test_the_transition_table_matches_the_specification(): void
    {
        $expected = [
            'basket' => ['pending_payment'],
            'pending_payment' => [
                'confirmed',
                'awaiting_cross_border',
                'cancelled_by_customer',
                'cancelled_non_payment',
            ],
            'awaiting_cross_border' => ['confirmed', 'cancelled_by_customer'],
            'confirmed' => [
                'vehicle_released',
                'cancelled_failed_kyc',
                'cancelled_by_customer',
                'no_show',
            ],
            'vehicle_released' => ['completed'],
            'completed' => [],
            'cancelled_by_customer' => [],
            'cancelled_non_payment' => [],
            'cancelled_failed_kyc' => [],
            'no_show' => [],
        ];

        foreach (BookingStatus::cases() as $status) {
            $this->assertArrayHasKey(
                $status->value,
                $expected,
                "State {$status->value} is missing from the expected table."
            );

            $actual = array_map(
                static fn (BookingStatus $s): string => $s->value,
                $this->machine->allowedTransitions($status),
            );

            $this->assertSame(
                $expected[$status->value],
                $actual,
                "Transitions from {$status->value} do not match the specification."
            );
        }

        $this->assertCount(
            count($expected),
            BookingStatus::cases(),
            'The enum and the expected table have drifted apart.'
        );
    }

    public function test_an_undefined_transition_is_refused(): void
    {
        $this->expectException(InvalidBookingTransitionException::class);
        $this->expectExceptionMessage('cannot move from pending_payment to completed');

        $this->machine->assertCanTransition(
            BookingStatus::PendingPayment,
            BookingStatus::Completed,
            TransitionActor::Staff,
        );
    }

    public function test_a_terminal_state_goes_nowhere(): void
    {
        $this->assertSame([], $this->machine->allowedTransitions(BookingStatus::Completed));
        $this->assertSame([], $this->machine->allowedTransitions(BookingStatus::NoShow));
        $this->assertSame([], $this->machine->allowedTransitions(BookingStatus::CancelledFailedKyc));
    }

    public function test_a_customer_may_cancel_their_own_unpaid_booking(): void
    {
        $this->machine->assertCanTransition(
            BookingStatus::PendingPayment,
            BookingStatus::CancelledByCustomer,
            TransitionActor::Customer,
        );

        $this->addToAssertionCount(1);
    }

    public function test_a_customer_may_not_confirm_their_own_payment(): void
    {
        // Only a staff member confirms payment. Proof upload never does.
        $this->expectException(InvalidBookingTransitionException::class);
        $this->expectExceptionMessage('A customer may not move a booking');

        $this->machine->assertCanTransition(
            BookingStatus::PendingPayment,
            BookingStatus::Confirmed,
            TransitionActor::Customer,
        );
    }

    public function test_only_the_system_cancels_for_non_payment(): void
    {
        $this->assertTrue($this->machine->canTransition(
            BookingStatus::PendingPayment,
            BookingStatus::CancelledNonPayment,
            TransitionActor::System,
        ));

        $this->assertFalse($this->machine->canTransition(
            BookingStatus::PendingPayment,
            BookingStatus::CancelledNonPayment,
            TransitionActor::Staff,
        ));
    }

    public function test_a_cross_border_booking_waits_for_paperwork_instead_of_confirming(): void
    {
        $this->assertTrue($this->machine->canTransition(
            BookingStatus::PendingPayment,
            BookingStatus::AwaitingCrossBorder,
            TransitionActor::Staff,
        ));

        $this->assertTrue($this->machine->canTransition(
            BookingStatus::AwaitingCrossBorder,
            BookingStatus::Confirmed,
            TransitionActor::Staff,
        ));
    }

    public function test_a_vehicle_cannot_be_released_while_a_balance_is_owing(): void
    {
        $this->expectException(InvalidBookingTransitionException::class);
        $this->expectExceptionMessage('cannot be released while 625.00 remains outstanding');

        $this->machine->assertCanTransition(
            BookingStatus::Confirmed,
            BookingStatus::VehicleReleased,
            TransitionActor::Staff,
            new TransitionContext(balanceDue: '625.00', securityDepositCollected: true),
        );
    }

    public function test_a_vehicle_cannot_be_released_before_the_security_deposit_is_taken(): void
    {
        $this->expectException(InvalidBookingTransitionException::class);
        $this->expectExceptionMessage('security deposit');

        $this->machine->assertCanTransition(
            BookingStatus::Confirmed,
            BookingStatus::VehicleReleased,
            TransitionActor::Staff,
            new TransitionContext(balanceDue: '0.00', securityDepositCollected: false),
        );
    }

    public function test_release_is_refused_when_no_facts_are_supplied(): void
    {
        // Fails closed. This transition hands a physical car to someone; a
        // caller that cannot say whether the balance is settled must not be
        // waved through on the assumption that it is.
        $this->expectException(InvalidBookingTransitionException::class);

        $this->machine->assertCanTransition(
            BookingStatus::Confirmed,
            BookingStatus::VehicleReleased,
            TransitionActor::Staff,
        );
    }

    public function test_a_vehicle_is_released_when_everything_is_settled(): void
    {
        $this->machine->assertCanTransition(
            BookingStatus::Confirmed,
            BookingStatus::VehicleReleased,
            TransitionActor::Staff,
            new TransitionContext(balanceDue: '0.00', securityDepositCollected: true),
        );

        $this->addToAssertionCount(1);
    }

    public function test_a_zero_balance_written_unscaled_still_counts_as_settled(): void
    {
        // '0' rather than '0.00' — the form of a value read straight back from
        // SQL. A string comparison would treat these as different.
        $this->machine->assertCanTransition(
            BookingStatus::Confirmed,
            BookingStatus::VehicleReleased,
            TransitionActor::Staff,
            new TransitionContext(balanceDue: '0', securityDepositCollected: true),
        );

        $this->addToAssertionCount(1);
    }

    public function test_states_report_whether_they_still_claim_a_vehicle(): void
    {
        // Drives the expiry sweep and staff reassignment: once a booking stops
        // claiming, its hold must be released or the vehicle silently vanishes
        // from the bookable fleet.
        $this->assertTrue(BookingStatus::PendingPayment->claimsVehicle());
        $this->assertTrue(BookingStatus::Confirmed->claimsVehicle());
        $this->assertTrue(BookingStatus::AwaitingCrossBorder->claimsVehicle());
        $this->assertTrue(BookingStatus::VehicleReleased->claimsVehicle());

        $this->assertFalse(BookingStatus::Completed->claimsVehicle());
        $this->assertFalse(BookingStatus::CancelledNonPayment->claimsVehicle());
        $this->assertFalse(BookingStatus::NoShow->claimsVehicle());
        $this->assertFalse(BookingStatus::Basket->claimsVehicle());
    }
}
