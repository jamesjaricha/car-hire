<?php

declare(strict_types=1);

namespace App\Services\Bookings;

use App\Contracts\BookingStateMachineContract;
use App\DataTransferObjects\TransitionContext;
use App\Enums\BookingStatus;
use App\Enums\TransitionActor;
use App\Exceptions\InvalidBookingTransitionException;

/**
 * Spec §7.3, transcribed and enforced.
 *
 * The map below is deliberately written as plain strings in one const, so it can
 * be read side by side with the specification table and checked line for line.
 * That readability is the point: this is the document that says what a booking
 * is allowed to do, and it should be reviewable by someone who does not read
 * PHP fluently.
 *
 * Anything not in the map is refused.
 */
final class BookingStateMachine implements BookingStateMachineContract
{
    /**
     * from => [ to => [permitted actors] ]
     *
     * @var array<string, array<string, list<string>>>
     */
    private const MAP = [
        'basket' => [
            // Checkout submitted.
            'pending_payment' => ['customer'],
        ],

        'pending_payment' => [
            // Payment confirmed by a permissioned staff member. Never by the
            // customer uploading proof.
            'confirmed' => ['staff'],
            // Payment confirmed, but the booking crosses a border, so it waits
            // for paperwork instead of confirming outright. Spec §11.
            'awaiting_cross_border' => ['staff'],
            'cancelled_by_customer' => ['customer', 'staff'],
            // Deadline elapsed. The scheduled sweep, not a person.
            'cancelled_non_payment' => ['system'],
        ],

        'awaiting_cross_border' => [
            'confirmed' => ['staff'],
            // Paperwork unobtainable: full refund, no admin fee, because the
            // failure is operational rather than the customer's doing.
            'cancelled_by_customer' => ['staff'],
        ],

        'confirmed' => [
            // Guarded — see assertReleaseGuards().
            'vehicle_released' => ['staff'],
            'cancelled_failed_kyc' => ['staff'],
            'cancelled_by_customer' => ['staff'],
            'no_show' => ['staff', 'system'],
        ],

        'vehicle_released' => [
            'completed' => ['staff'],
        ],

        // Terminal states. No transitions out, by design: a completed or
        // cancelled booking is history, and history is corrected by creating
        // something new, not by editing the past.
        'completed' => [],
        'cancelled_by_customer' => [],
        'cancelled_non_payment' => [],
        'cancelled_failed_kyc' => [],
        'no_show' => [],
    ];

    /**
     * @return list<BookingStatus>
     */
    public function allowedTransitions(BookingStatus $from): array
    {
        return array_values(array_map(
            static fn (string $to): BookingStatus => BookingStatus::from($to),
            array_keys(self::MAP[$from->value] ?? []),
        ));
    }

    public function canTransition(
        BookingStatus $from,
        BookingStatus $to,
        ?TransitionActor $actor = null,
    ): bool {
        $actors = self::MAP[$from->value][$to->value] ?? null;

        if ($actors === null) {
            return false;
        }

        if ($actor === null) {
            return true;
        }

        return in_array($actor->value, $actors, true);
    }

    public function assertCanTransition(
        BookingStatus $from,
        BookingStatus $to,
        TransitionActor $actor,
        ?TransitionContext $context = null,
    ): void {
        if (! $this->canTransition($from, $to)) {
            throw InvalidBookingTransitionException::notAllowed($from, $to);
        }

        if (! $this->canTransition($from, $to, $actor)) {
            throw InvalidBookingTransitionException::wrongActor($from, $to, $actor);
        }

        if ($to === BookingStatus::VehicleReleased) {
            $this->assertReleaseGuards($context ?? TransitionContext::none());
        }
    }

    /**
     * @return list<TransitionActor>
     */
    public function actorsFor(BookingStatus $from, BookingStatus $to): array
    {
        return array_values(array_map(
            static fn (string $actor): TransitionActor => TransitionActor::from($actor),
            self::MAP[$from->value][$to->value] ?? [],
        ));
    }

    /**
     * Spec §14.6: a vehicle cannot be released without KYC verified, the
     * balance settled and the security deposit recorded.
     *
     * A caller that supplies no context is refused rather than allowed through.
     * The absent-facts case must fail closed — this is the transition that hands
     * a physical car to someone.
     */
    private function assertReleaseGuards(TransitionContext $context): void
    {
        if ($context->hasOutstandingBalance()) {
            throw InvalidBookingTransitionException::balanceOutstanding(
                $context->balanceDue ?? 'an unknown amount'
            );
        }

        if ($context->securityDepositCollected !== true) {
            throw InvalidBookingTransitionException::securityDepositNotTaken();
        }

        // KYC verification is not enforced yet — there is nothing to read it
        // from until the admin panel exists. When kyc_documents lands, add:
        //
        //     if ($context->kycVerified !== true) { throw ...; }
        //
        // and the corresponding test from spec §14.6.
    }
}
