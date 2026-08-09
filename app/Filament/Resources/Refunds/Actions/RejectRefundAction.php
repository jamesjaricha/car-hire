<?php

declare(strict_types=1);

namespace App\Filament\Resources\Refunds\Actions;

use App\Contracts\RefundRequestServiceContract;
use App\Enums\StaffPermission;
use App\Exceptions\RefundNotApprovableException;
use App\Exceptions\StaffPermissionDeniedException;
use App\Models\Refund;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * Refusing a refund.
 *
 * The reason is required, and the modal says why: the customer asked for money
 * back and is being told no. That is the refund outcome most likely to be
 * disputed months later, and the only defence is a record of who decided and on
 * what grounds.
 *
 * Rejecting does NOT un-cancel the booking. The hire is still off; what has been
 * decided is that no money goes back. The notification says so, because staff
 * will otherwise assume one implies the other.
 */
final class RejectRefundAction
{
    public static function make(string $name = 'rejectRefund'): Action
    {
        return Action::make($name)
            ->label('Reject')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->modalHeading('Reject this refund')
            ->modalDescription('The booking stays cancelled. This decides only that no money goes back.')
            ->modalSubmitActionLabel('Reject')
            ->schema([
                Textarea::make('reason')
                    ->label('Why is it being refused?')
                    ->rows(3)
                    ->required()
                    ->maxLength(500)
                    ->helperText('The customer expected this money. Recorded permanently against your name.'),
            ])
            ->visible(fn (Refund $record): bool => self::isOfferable($record))
            ->action(function (Refund $record, array $data): void {
                /** @var User $actor */
                $actor = auth()->user();

                try {
                    app(RefundRequestServiceContract::class)->reject(
                        actor: $actor,
                        refund: $record,
                        reason: (string) $data['reason'],
                    );
                } catch (StaffPermissionDeniedException|RefundNotApprovableException $e) {
                    Notification::make()
                        ->title('The refund was not rejected')
                        ->body($e->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Refund rejected')
                    ->body('No money will be paid back. The booking remains cancelled.')
                    ->success()
                    ->send();
            });
    }

    private static function isOfferable(Refund $record): bool
    {
        $user = auth()->user();

        // Rejecting is the same authority as approving: someone who may decide
        // money leaves may also decide it does not.
        if (! $user instanceof User || ! $user->hasPermissionTo(StaffPermission::RefundsApprove)) {
            return false;
        }

        return $record->status->canBeRejected();
    }
}
