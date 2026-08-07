<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Contracts\AuditLoggerContract;
use App\Contracts\PaymentDeadlineCalculatorContract;
use App\Contracts\PaymentDeadlineExtensionServiceContract;
use App\Contracts\VehicleHoldServiceContract;
use App\DataTransferObjects\AuditEntry;
use App\Enums\AuditAction;
use App\Enums\BookingStatus;
use App\Enums\StaffPermission;
use App\Exceptions\DeadlineNotExtendableException;
use App\Exceptions\StaffPermissionDeniedException;
use App\Models\Booking;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Staff giving a customer longer to pay. Spec §8.2.
 *
 * WHY THIS IS NOT AN UPDATE STATEMENT
 *
 * A payment deadline is one fact stored in two places: the booking's
 * `payment_deadline_at`, and the `expires_at` of the hold keeping the vehicle
 * off sale until then. Setting only the first hands the customer another
 * twenty-four hours and simultaneously releases their car to the next person
 * who searches for it — the same failure as a confirmed booking losing its
 * vehicle, arriving by a different route. Both move here, together, inside one
 * transaction.
 *
 * WHAT IT DELIBERATELY DOES NOT POLICE
 *
 * Spec §8.2 allows staff to "manually extend any deadline or approve an
 * exception", so there is no cap on how far. A manager who has spoken to the
 * customer and knows the transfer left this morning has information the
 * automatic rule does not. What is refused is incoherence rather than
 * generosity: a deadline after the pickup it precedes, a booking that never had
 * a deadline, or one that has stopped waiting to be paid.
 */
final class PaymentDeadlineExtensionService implements PaymentDeadlineExtensionServiceContract
{
    public function __construct(
        private readonly PaymentDeadlineCalculatorContract $deadlines,
        private readonly VehicleHoldServiceContract $holds,
        private readonly AuditLoggerContract $audit,
    ) {}

    public function extend(
        User $actor,
        Booking $booking,
        CarbonImmutable $newDeadline,
        ?string $reason = null,
    ): Booking {
        // Before any lock: a refusal should not queue behind other work, and
        // nothing about the answer depends on the booking's current state.
        if (! $actor->hasPermissionTo(StaffPermission::PaymentsExtendDeadline)) {
            throw StaffPermissionDeniedException::missing(StaffPermission::PaymentsExtendDeadline);
        }

        return DB::transaction(function () use ($booking, $newDeadline, $actor, $reason): Booking {
            $locked = Booking::query()->whereKey($booking->getKey())->lockForUpdate()->first();

            if (! $locked instanceof Booking) {
                throw DeadlineNotExtendableException::bookingHasNoDeadline($booking->reference);
            }

            $this->assertExtendable($locked, $newDeadline);

            $now = CarbonImmutable::now();
            $previousDeadline = $locked->payment_deadline_at;

            $locked->forceFill([
                'payment_deadline_at' => $newDeadline,
                // Recalculated by the same rule that set it originally, rather
                // than left pointing at an instant inside the old window — a
                // reminder that has already passed will never fire, and the
                // customer gets extra time with no nudge at all.
                'payment_reminder_at' => $this->deadlines->reminderFor($now, $newDeadline),
            ])->save();

            // The vehicle stays claimed for exactly as long as the new promise.
            $this->holds->extendToDeadline($locked, $newDeadline);

            $this->audit->record(new AuditEntry(
                action: AuditAction::PaymentDeadlineExtended,
                actor: $actor,
                booking: $locked,
                // The booking has not moved; recording it unchanged keeps the
                // trail readable rather than leaving the columns empty.
                statusBefore: $locked->status,
                statusAfter: $locked->status,
                notes: $reason,
                metadata: [
                    'previous_deadline_at' => $previousDeadline->toIso8601String(),
                    'new_deadline_at' => $newDeadline->toIso8601String(),
                    'extended_by_seconds' => $newDeadline->getTimestamp() - $previousDeadline->getTimestamp(),
                ],
            ));

            return $locked;
        }, attempts: 3);
    }

    private function assertExtendable(Booking $booking, CarbonImmutable $newDeadline): void
    {
        // Only a booking still waiting for money has a deadline that means
        // anything. Extending a confirmed one would suggest it might still
        // expire; extending a cancelled one would suggest it might come back.
        if ($booking->status !== BookingStatus::PendingPayment) {
            throw DeadlineNotExtendableException::bookingIsNotAwaitingPayment(
                $booking->reference,
                $booking->status,
            );
        }

        if ($booking->payment_deadline_at === null) {
            throw DeadlineNotExtendableException::bookingHasNoDeadline($booking->reference);
        }

        $now = CarbonImmutable::now();

        if ($newDeadline->lessThanOrEqualTo($now)) {
            throw DeadlineNotExtendableException::notInTheFuture($newDeadline, $now);
        }

        // Refused as meaningless, not as generous: a customer whose deadline
        // falls after pickup could collect a car they have not paid for and
        // still be inside their window.
        if ($newDeadline->greaterThanOrEqualTo($booking->pickup_at)) {
            throw DeadlineNotExtendableException::afterPickup($newDeadline, $booking->pickup_at);
        }

        if ($newDeadline->lessThanOrEqualTo($booking->payment_deadline_at)) {
            throw DeadlineNotExtendableException::notAnExtension(
                $newDeadline,
                $booking->payment_deadline_at,
            );
        }
    }
}
