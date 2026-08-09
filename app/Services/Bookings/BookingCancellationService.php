<?php

declare(strict_types=1);

namespace App\Services\Bookings;

use App\Contracts\AuditLoggerContract;
use App\Contracts\BookingCancellationServiceContract;
use App\Contracts\BookingStateMachineContract;
use App\Contracts\VehicleHoldServiceContract;
use App\DataTransferObjects\AuditEntry;
use App\Enums\AuditAction;
use App\Enums\BookingStatus;
use App\Enums\TransitionActor;
use App\Exceptions\InvalidBookingTransitionException;
use App\Models\Booking;
use App\Models\User;
use App\Models\VehicleHold;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A person ending a booking. The counterpart of `BookingExpiryService`, which is
 * the clock ending one.
 *
 * WHY THIS EXISTS AT ALL
 *
 * `BookingStateMachine` only ever answers questions — it says whether a move is
 * permitted and never performs one. Something has to actually write the status,
 * stamp the time, release the vehicle and record who decided. Until Phase 4 the
 * only code doing that was the expiry sweep, inline, for its own single case.
 *
 * Putting it in the Filament action instead would have made the panel a writer
 * of booking statuses, which is precisely what `BookingPolicy` and ARCHITECTURE
 * §11 exist to prevent. Putting it inside the refund service would have coupled
 * two things that must stay separable: a booking can be cancelled without a
 * refund, and a refund is a decision about money rather than about a booking.
 *
 * THE HOLD IS RELEASED, AND THAT IS THE POINT
 *
 * `BookingStatus::claimsVehicle()` is false for every state this service moves
 * to, so leaving the hold in place would keep a cancelled booking's car off sale
 * until the original hire ended. Those days cannot be resold, and nothing would
 * ever have flagged it — the vehicle simply would not appear in searches. This
 * is a revenue leak rather than a correctness bug, which is why it survived
 * three phases.
 *
 * ON PERMISSIONS — READ THIS BEFORE ADDING A SECOND CALLER
 *
 * This service asserts NO permission of its own, and that is a departure from
 * ARCHITECTURE §10, which says permissions are checked in the service rather
 * than only at the edge. The reason is that spec §12 defines no permission for
 * cancelling a booking — there is no `bookings.cancel` to check. Inventing one
 * silently would be a fourth undocumented departure from §12, and this codebase
 * has a standing rule that those are decisions for the operator rather than for
 * whoever is typing.
 *
 * So authorisation currently lives with the caller. The only caller is the
 * panel's cancel-and-refund action, gated on `refunds.request`, which every role
 * holds. If you add a second caller, gate it — and see `docs/OPEN-ITEMS.md`,
 * where this is recorded as an outstanding §12 question rather than an oversight.
 */
final class BookingCancellationService implements BookingCancellationServiceContract
{
    public function __construct(
        private readonly BookingStateMachineContract $stateMachine,
        private readonly VehicleHoldServiceContract $holds,
        private readonly AuditLoggerContract $audit,
    ) {}

    public function cancel(
        User $actor,
        Booking $booking,
        BookingStatus $to,
        ?string $reason = null,
    ): Booking {
        // Checked before the lock because it depends on nothing but the
        // argument. The state machine would refuse most wrong answers, but not
        // all of them — `confirmed` is a legal move from `pending_payment`, and
        // reaching it through a method called cancel() would confirm a booking
        // and release its vehicle hold in the same breath.
        if (! $this->isAnEnding($to)) {
            throw InvalidBookingTransitionException::notACancellation($to);
        }

        return DB::transaction(function () use ($actor, $booking, $to, $reason): Booking {
            $locked = Booking::query()->whereKey($booking->getKey())->lockForUpdate()->first();

            if (! $locked instanceof Booking) {
                throw InvalidBookingTransitionException::notAllowed($booking->status, $to);
            }

            $statusBefore = $locked->status;

            // Asked under the lock, against the row as it actually is. The
            // caller's copy may be minutes old, and a booking confirmed in that
            // gap is one this cancellation must be re-checked against — §7.3
            // permits different endings from different states.
            $this->stateMachine->assertCanTransition($statusBefore, $to, TransitionActor::Staff);

            $now = CarbonImmutable::now();

            $locked->forceFill([
                'status' => $to,
                // Stamped for a no-show as well as for a cancellation. It is the
                // moment the booking ended, and leaving it null for one of the
                // five endings would make "when did this stop" a question with
                // two different answers depending on how it stopped.
                'cancelled_at' => $now,
                'cancellation_reason' => $reason,
            ])->save();

            $releasedHolds = $this->releaseHolds($locked);

            $this->audit->record(new AuditEntry(
                action: AuditAction::BookingCancelled,
                actor: $actor,
                booking: $locked,
                statusBefore: $statusBefore,
                statusAfter: $to,
                notes: $reason,
                metadata: [
                    'cancelled_at' => $now->toIso8601String(),
                    // Which vehicles went back on sale, and how many. A booking
                    // that ended with nothing released is worth being able to
                    // spot later — it means either a short-notice booking that
                    // never held one, or a hold this missed.
                    'holds_released' => $releasedHolds,
                    'amount_held' => $locked->amount_paid,
                ],
            ));

            return $locked;
        }, attempts: 3);
    }

    /**
     * Whether this status is a way for a booking to stop.
     *
     * `completed` is deliberately excluded despite being terminal: a completed
     * hire is a booking that finished properly, and routing it through a
     * cancellation would stamp `cancelled_at` on a car that came back clean.
     */
    private function isAnEnding(BookingStatus $to): bool
    {
        return $to->isCancellation() || $to === BookingStatus::NoShow;
    }

    /**
     * Put the vehicle back on sale.
     *
     * Through `VehicleHoldService`, which is the only code permitted to write to
     * `vehicle_holds`. Every unreleased hold, not merely the newest — a failed
     * reassignment could have left two, and releasing one of them would leave
     * the vehicle claimed by a booking that no longer exists.
     *
     * @return list<int> the ids released
     */
    private function releaseHolds(Booking $booking): array
    {
        $holds = VehicleHold::query()
            ->where('booking_id', $booking->getKey())
            ->whereNull('released_at')
            ->orderBy('id')
            ->get();

        $released = [];

        foreach ($holds as $hold) {
            $this->holds->release($hold);

            $released[] = (int) $hold->getKey();
        }

        return $released;
    }
}
