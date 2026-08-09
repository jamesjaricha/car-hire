<?php

declare(strict_types=1);

namespace App\Filament\Resources\Bookings\Actions;

use App\Contracts\BookingCancellationServiceContract;
use App\Contracts\BookingStateMachineContract;
use App\Contracts\RefundCalculatorContract;
use App\Contracts\RefundRequestServiceContract;
use App\DataTransferObjects\RefundQuote;
use App\Enums\PaymentMethodCode;
use App\Enums\RefundReason;
use App\Enums\StaffPermission;
use App\Enums\TransitionActor;
use App\Exceptions\InvalidBookingTransitionException;
use App\Exceptions\RefundNotRequestableException;
use App\Exceptions\StaffPermissionDeniedException;
use App\Models\Booking;
use App\Models\PaymentMethod;
use App\Models\Refund;
use App\Models\User;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;

/**
 * One button, two services, one transaction.
 *
 * Cancelling a booking and refunding its customer are one decision to the person
 * at the desk and two entirely separate things to the system — so the panel
 * offers one action, and it calls `BookingCancellationService` and
 * `RefundRequestService` in a single transaction without either learning that
 * the other exists.
 *
 * That separation is not tidiness. A cross-border booking is cancelled by the
 * operator's own failure and a customer cancellation is theirs; a booking can be
 * cancelled with nothing to refund; and a refund will eventually need to exist
 * for reasons that are not cancellations at all. Fusing them now would have to
 * be unpicked then.
 *
 * THE FIGURE IS SHOWN BEFORE IT IS COMMITTED
 *
 * `RefundCalculator` is pure, so the modal can quote live as the reason changes.
 * Staff should never raise a refund and discover the amount afterwards — they
 * are usually on the telephone to the customer while they do this.
 *
 * WHEN NOTHING IS REFUNDABLE
 *
 * A late cancellation can forfeit exactly what was paid. The booking is still
 * cancelled, no refund row is created — one for zero could never be disbursed,
 * because §9.3 wants a reference and there is none for money that did not move —
 * and the notification says plainly that nothing is coming back. The calculation
 * survives in the cancellation's audit entry.
 */
final class CancelAndRefundAction
{
    public static function make(string $name = 'cancelAndRefund'): Action
    {
        return Action::make($name)
            ->label('Cancel and refund')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('danger')
            ->modalHeading('Cancel this booking')
            ->modalDescription('The vehicle goes back on sale immediately. Any refund is computed from spec §9 and cannot be edited.')
            ->modalSubmitActionLabel('Cancel the booking')
            ->schema(fn (Booking $record): array => [
                Select::make('reason')
                    ->label('Why is it being cancelled?')
                    ->options(self::reasonOptions($record))
                    ->default(RefundReason::CustomerCancellation->value)
                    ->required()
                    // Live, so the quote below re-computes as they choose. This
                    // is the field that decides how much money goes back.
                    ->live()
                    ->helperText(fn (Get $get): string => self::ruleFor($get)),

                Select::make('method')
                    ->label('How will the money go back?')
                    ->options(self::methodOptions())
                    ->default(PaymentMethodCode::Cash->value)
                    ->required()
                    ->visible(fn (Get $get): bool => self::quoteFor($record, $get)?->hasAnythingToRefund() ?? false),

                TextEntry::make('quote')
                    ->label('Refund due')
                    ->state(fn (Get $get): string => self::quoteSummary($record, $get)),

                Textarea::make('notes')
                    ->label('Notes')
                    ->rows(2)
                    ->maxLength(500)
                    ->helperText('Recorded against the cancellation in the audit trail.'),
            ])
            ->visible(fn (Booking $record): bool => self::isOfferable($record))
            ->action(function (Booking $record, array $data): void {
                /** @var User $actor */
                $actor = auth()->user();

                $reason = RefundReason::from($data['reason']);
                $notes = $data['notes'] ?? null;

                try {
                    $refund = self::cancelAndRaise($actor, $record, $reason, $data, $notes);
                } catch (
                    StaffPermissionDeniedException
                    |InvalidBookingTransitionException $e
                ) {
                    // Nothing was written: both services run inside one
                    // transaction, so a refusal leaves neither a cancelled
                    // booking nor a dangling refund.
                    Notification::make()
                        ->title('The booking was not cancelled')
                        ->body($e->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                self::report($record, $refund);
            });
    }

    /**
     * Both writes, or neither.
     *
     * A cancelled booking with no refund raised against it is a customer whose
     * money the operator is holding with nothing in the system saying so — and
     * that is precisely the queue Phase 3 left behind. The transaction is what
     * stops the panel recreating it one failed request at a time.
     *
     * @param  array<string, mixed>  $data
     */
    private static function cancelAndRaise(
        User $actor,
        Booking $booking,
        RefundReason $reason,
        array $data,
        ?string $notes,
    ): ?Refund {
        return DB::transaction(function () use ($actor, $booking, $reason, $data, $notes): ?Refund {
            $cancelled = app(BookingCancellationServiceContract::class)->cancel(
                actor: $actor,
                booking: $booking,
                to: $reason->cancelsBookingTo(),
                reason: $notes,
            );

            try {
                return app(RefundRequestServiceContract::class)->request(
                    actor: $actor,
                    booking: $cancelled,
                    reason: $reason,
                    method: PaymentMethodCode::from($data['method'] ?? PaymentMethodCode::Cash->value),
                    notes: $notes,
                );
            } catch (RefundNotRequestableException) {
                // Nothing is owed, or a refund is already open. Neither is a
                // reason to leave the booking live — the cancellation stands and
                // the audit entry carries the calculation. Caught inside the
                // transaction rather than outside it: the inner service runs in
                // a savepoint, so its rollback does not take the cancellation
                // with it.
                return null;
            }
        }, attempts: 3);
    }

    private static function report(Booking $booking, ?Refund $refund): void
    {
        if (! $refund instanceof Refund) {
            Notification::make()
                ->title('Booking cancelled — no refund is due')
                ->body(
                    'The amount held is entirely accounted for by the forfeited booking deposit and the '
                    .'admin fee, so no refund has been raised. The calculation is in the audit trail.'
                )
                ->warning()
                ->persistent()
                ->send();

            return;
        }

        $body = sprintf(
            '%s %s has been raised for approval. Spec §9.3 requires somebody else to approve it before '
            .'the money can be paid out.',
            $refund->currency,
            $refund->amount,
        );

        if ($refund->admin_fee_was_placeholder) {
            $body .= ' Note: the flat admin fee is still an undecided placeholder (§15.1), so none was deducted.';
        }

        Notification::make()
            ->title('Booking cancelled, refund raised')
            ->body($body)
            ->success()
            ->persistent()
            ->send();
    }

    /**
     * Only the endings §7.3 permits from where this booking actually is.
     *
     * The state machine would refuse the rest, but offering a reason that cannot
     * work makes staff learn the rule by being told no — and on this screen that
     * means an unnecessary refusal in front of a customer on the telephone.
     *
     * Asked of the state machine rather than listed here, so that §7.3 is read
     * from the one place that holds it. The single exception is the cross-border
     * reason, which is filtered on the booking rather than on the transition:
     * §7.3 allows any booking to be cancelled by a customer, but §11's no-fee
     * full refund exists specifically for paperwork that could not be obtained,
     * and offering it on a domestic hire is offering a way to waive the admin
     * fee for no reason.
     *
     * @return array<string, string>
     */
    private static function reasonOptions(Booking $record): array
    {
        $machine = app(BookingStateMachineContract::class);

        return collect(RefundReason::cases())
            ->filter(fn (RefundReason $reason): bool => $reason !== RefundReason::CrossBorderPaperworkFailed
                || $record->isCrossBorder())
            ->filter(fn (RefundReason $reason): bool => $machine->canTransition(
                $record->status,
                $reason->cancelsBookingTo(),
                TransitionActor::Staff,
            ))
            ->mapWithKeys(fn (RefundReason $reason): array => [$reason->value => $reason->label()])
            ->all();
    }

    /**
     * Only the methods the operator actually accepts.
     *
     * @return array<string, string>
     */
    private static function methodOptions(): array
    {
        return PaymentMethod::query()
            ->inDisplayOrder()
            ->get()
            ->filter(fn (PaymentMethod $method): bool => $method->isOfferable())
            ->mapWithKeys(fn (PaymentMethod $method): array => [$method->code->value => $method->label])
            ->all();
    }

    private static function quoteFor(Booking $record, Get $get): ?RefundQuote
    {
        $reason = RefundReason::tryFrom((string) $get('reason'));

        if ($reason === null) {
            return null;
        }

        return app(RefundCalculatorContract::class)->quote($record, $reason);
    }

    private static function ruleFor(Get $get): string
    {
        return RefundReason::tryFrom((string) $get('reason'))?->description() ?? '';
    }

    /**
     * The §9 working, in the words somebody would say on the telephone.
     */
    private static function quoteSummary(Booking $record, Get $get): string
    {
        $quote = self::quoteFor($record, $get);

        if ($quote === null) {
            return 'Choose a reason to see what is refundable.';
        }

        if (! $quote->hasAnythingToRefund()) {
            return sprintf(
                'Nothing is refundable. %s %s was held, and it is entirely accounted for by the '
                .'forfeited booking deposit (%s) and the admin fee (%s). The booking will still be cancelled.',
                $record->currency,
                $quote->amountPaid,
                $quote->bookingDepositRetained,
                $quote->adminFeeDeducted,
            );
        }

        $summary = sprintf(
            '%s %s back to the customer. Held %s',
            $record->currency,
            $quote->amount,
            $quote->amountPaid,
        );

        if (Money::isPositive($quote->bookingDepositRetained)) {
            $summary .= sprintf(', less the %s booking deposit forfeited inside %d hours of pickup',
                $quote->bookingDepositRetained,
                $quote->noticeWindowHours,
            );
        }

        $summary .= sprintf(', less a %s admin fee.', $quote->adminFeeDeducted);

        if ($quote->adminFeeIsPlaceholder) {
            // The §15.1 warning, at the moment the figure is being committed to.
            $summary .= ' WARNING: the admin fee has not been decided by the business yet — it is a '
                .'placeholder of zero, so no fee has been deducted. Spec §15.1.';
        }

        return $summary;
    }

    private static function isOfferable(Booking $record): bool
    {
        $user = auth()->user();

        // Both halves of what this button does. `bookings.cancel` is not in §12
        // — see BookingCancellationService — and asking for the refund
        // permission too means the button is never offered to somebody who
        // could cancel the booking but not raise the refund that follows it,
        // which would leave a customer's money stranded.
        if (! $user instanceof User
            || ! $user->hasPermissionTo(StaffPermission::BookingsCancel)
            || ! $user->hasPermissionTo(StaffPermission::RefundsRequest)) {
            return false;
        }

        // A booking that has already ended has nothing to cancel, and one that
        // has no legal ending from here has nothing to offer.
        return ! $record->status->isTerminal()
            && self::reasonOptions($record) !== [];
    }
}
