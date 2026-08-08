<?php

declare(strict_types=1);

namespace App\Filament\Resources\Bookings\Actions;

use App\Contracts\CounterPaymentServiceContract;
use App\Enums\PaymentMethodCode;
use App\Enums\StaffPermission;
use App\Exceptions\PaymentMethodNotAvailableException;
use App\Exceptions\PaymentNotConfirmableException;
use App\Exceptions\PaymentNotRecordableException;
use App\Exceptions\StaffPermissionDeniedException;
use App\Models\Booking;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * Take money at the counter. Spec §5: the balance is settled before handover.
 *
 * THE AMOUNT IS TYPED, NOT ASSUMED
 *
 * The field defaults to the outstanding balance but stays editable, because
 * customers hand over the wrong figure and the system must record what actually
 * changed hands rather than what was expected. A shortfall is then reported
 * back — the same way it would be for a bank transfer.
 *
 * The action holds no permission logic of its own. `CounterPaymentService`
 * calls the recording and confirmation services in order, so a counter clerk
 * can take cash and still cannot sign off a bank transfer, exactly as §12 says.
 * The button is hidden where it cannot succeed; the service is what refuses it.
 */
final class TakeBalanceAction
{
    public static function make(string $name = 'takeBalance'): Action
    {
        return Action::make($name)
            ->label('Take payment')
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('success')
            ->modalHeading('Record money received')
            ->modalDescription('This records the payment AND confirms it. Only use it for money you have in front of you.')
            ->modalSubmitActionLabel('Record and confirm')
            ->schema(fn (Booking $record): array => [
                Select::make('method')
                    ->label('How was it paid?')
                    ->options(self::methodOptions())
                    ->default(PaymentMethodCode::Cash->value)
                    ->required(),

                TextInput::make('amount')
                    ->label('Amount received')
                    ->prefix($record->currency)
                    ->numeric()
                    ->required()
                    ->default(Money::of($record->balance_due))
                    ->helperText(sprintf(
                        'Outstanding: %s %s. Change this if a different amount was handed over.',
                        $record->currency,
                        Money::of($record->balance_due),
                    )),

                Textarea::make('notes')
                    ->label('Notes')
                    ->rows(2)
                    ->maxLength(500),
            ])
            ->visible(fn (Booking $record): bool => self::isOfferable($record))
            ->action(function (Booking $record, array $data): void {
                /** @var User $actor */
                $actor = auth()->user();

                try {
                    $result = app(CounterPaymentServiceContract::class)->take(
                        actor: $actor,
                        booking: $record,
                        code: PaymentMethodCode::from($data['method']),
                        amountReceived: (string) $data['amount'],
                        notes: $data['notes'] ?? null,
                    );
                } catch (
                    StaffPermissionDeniedException
                    |PaymentNotRecordableException
                    |PaymentNotConfirmableException
                    |PaymentMethodNotAvailableException $e
                ) {
                    // Nothing was written: the service runs in one transaction,
                    // so a refusal here leaves no receipt behind.
                    Notification::make()
                        ->title('No payment was recorded')
                        ->body($e->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title($result->hasOutstandingBalance() ? 'Part payment recorded' : 'Payment recorded in full')
                    ->body(self::outcome($result->balanceDue, $result->hasShortfall, $result->shortfallAmount, $record->currency))
                    ->success()
                    ->send();
            });
    }

    /**
     * Only the methods the operator actually accepts. A method switched off, or
     * killed by configuration, is off for staff as well as customers.
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

    private static function isOfferable(Booking $record): bool
    {
        $user = auth()->user();

        if (! $user instanceof User || ! $user->hasPermissionTo(StaffPermission::PaymentsRecordManual)) {
            return false;
        }

        // Nothing to take, or a booking that cannot accept money at all — the
        // guard added by the Phase 3 audit, which refuses payment against a
        // cancelled, released or completed booking.
        return $record->status->canAcceptPayment()
            && Money::isPositive($record->balance_due);
    }

    private static function outcome(string $balanceDue, bool $hasShortfall, string $shortfall, string $currency): string
    {
        if ($hasShortfall) {
            return sprintf(
                'Short by %s %s. %s %s remains outstanding on the booking.',
                $currency,
                $shortfall,
                $currency,
                $balanceDue,
            );
        }

        return Money::isPositive($balanceDue)
            ? sprintf('%s %s still outstanding.', $currency, $balanceDue)
            : 'The hire is now paid in full.';
    }
}
