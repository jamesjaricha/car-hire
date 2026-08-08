<?php

declare(strict_types=1);

namespace App\Filament\Resources\Bookings\Actions;

use App\Contracts\PaymentDeadlineExtensionServiceContract;
use App\Enums\BookingStatus;
use App\Enums\StaffPermission;
use App\Exceptions\DeadlineNotExtendableException;
use App\Exceptions\StaffPermissionDeniedException;
use App\Models\Booking;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * Give a customer longer to pay. Spec §8.2, §12 `payments.extend-deadline`.
 *
 * The action collects a new deadline and a reason, then hands both to
 * `PaymentDeadlineExtensionService`. It contains no rule about what is
 * allowed — the service refuses a deadline after pickup, one in the past, one
 * that shortens rather than extends, and a booking that has stopped waiting to
 * be paid. Duplicating any of that here would create a second opinion, and the
 * two would eventually disagree.
 */
final class ExtendDeadlineAction
{
    public static function make(string $name = 'extendDeadline'): Action
    {
        return Action::make($name)
            ->label('Extend deadline')
            ->icon(Heroicon::OutlinedClock)
            ->color('warning')
            ->modalHeading('Extend the payment deadline')
            ->modalDescription('The vehicle stays held for exactly as long as the new deadline.')
            ->modalSubmitActionLabel('Extend')
            ->schema([
                DateTimePicker::make('deadline')
                    ->label('New deadline')
                    ->seconds(false)
                    ->required()
                    ->helperText('Shown in your local time. It must fall before pickup.')
                    ->default(fn (Booking $record): ?CarbonImmutable => $record->payment_deadline_at?->addDay()),

                Textarea::make('reason')
                    ->label('Why')
                    ->rows(2)
                    ->maxLength(500)
                    ->helperText('Recorded in the audit trail against your name.'),
            ])
            // Only ever offered where it means something. The service refuses
            // these cases anyway; hiding the button stops staff clicking it and
            // being told no, which is a worse way to learn a rule.
            ->visible(fn (Booking $record): bool => self::isOfferable($record))
            ->action(function (Booking $record, array $data): void {
                /** @var User $actor */
                $actor = auth()->user();

                try {
                    $booking = app(PaymentDeadlineExtensionServiceContract::class)->extend(
                        actor: $actor,
                        booking: $record,
                        newDeadline: CarbonImmutable::parse($data['deadline']),
                        reason: $data['reason'] ?? null,
                    );
                } catch (StaffPermissionDeniedException|DeadlineNotExtendableException $e) {
                    // A refusal is a sentence for the person at the screen, not
                    // a stack trace. Anything else is a fault and is left to
                    // bubble, because flattening every exception into a red
                    // toast is how real bugs get mistaken for user error.
                    Notification::make()
                        ->title('The deadline was not changed')
                        ->body($e->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Deadline extended')
                    ->body(sprintf(
                        '%s now has until %s to pay.',
                        $booking->reference,
                        $booking->payment_deadline_at
                            ->setTimezone((string) config('carhire.display_timezone'))
                            ->format('j M Y H:i'),
                    ))
                    ->success()
                    ->send();
            });
    }

    private static function isOfferable(Booking $record): bool
    {
        $user = auth()->user();

        if (! $user instanceof User || ! $user->hasPermissionTo(StaffPermission::PaymentsExtendDeadline)) {
            return false;
        }

        // A short-notice booking has no deadline to extend, and a booking that
        // is no longer awaiting payment has nothing a deadline would govern.
        return $record->status === BookingStatus::PendingPayment
            && $record->payment_deadline_at !== null;
    }
}
