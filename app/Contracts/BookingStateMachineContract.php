<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DataTransferObjects\TransitionContext;
use App\Enums\BookingStatus;
use App\Enums\TransitionActor;
use App\Exceptions\InvalidBookingTransitionException;

/**
 * The authority on how a booking may move between states.
 *
 * The transition table is spec §7.3, transcribed. Anything absent from it is
 * refused — the guideline requires undefined transitions to fail loudly rather
 * than silently doing nothing, because a booking that quietly declines to change
 * state is indistinguishable from one that changed.
 */
interface BookingStateMachineContract
{
    /**
     * Every state reachable from the given one.
     *
     * @return list<BookingStatus>
     */
    public function allowedTransitions(BookingStatus $from): array;

    public function canTransition(
        BookingStatus $from,
        BookingStatus $to,
        ?TransitionActor $actor = null,
    ): bool;

    /**
     * @throws InvalidBookingTransitionException when the transition is not in
     *                                           the specification, the actor may not make it, or a guard fails.
     */
    public function assertCanTransition(
        BookingStatus $from,
        BookingStatus $to,
        TransitionActor $actor,
        ?TransitionContext $context = null,
    ): void;

    /**
     * Which kinds of actor may make a given transition.
     *
     * @return list<TransitionActor>
     */
    public function actorsFor(BookingStatus $from, BookingStatus $to): array;
}
