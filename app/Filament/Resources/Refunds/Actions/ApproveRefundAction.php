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
 * Spec §9.3's second person.
 *
 * The action holds no rule of its own. `RefundRequestService` refuses an
 * approval by the requester, an approval of something already decided, and an
 * approver without the permission — and the table's CHECK constraint refuses the
 * first of those even if this code were bypassed entirely.
 *
 * WHY THE BUTTON IS HIDDEN FROM THE REQUESTER
 *
 * The service would refuse them, so this is not the guard. It is that being
 * offered a button and then told you are not allowed to press it reads as a
 * system fault rather than as a control — and this particular control exists to
 * protect the person as much as the business. Better that it is simply not
 * theirs to press.
 */
final class ApproveRefundAction
{
    public static function make(string $name = 'approveRefund'): Action
    {
        return Action::make($name)
            ->label('Approve')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->modalHeading('Approve this refund')
            ->modalDescription(fn (Refund $record): string => self::summary($record))
            ->modalSubmitActionLabel('Approve')
            ->schema([
                Textarea::make('notes')
                    ->label('Notes')
                    ->rows(2)
                    ->maxLength(500)
                    ->helperText('Recorded in the audit trail against your name.'),
            ])
            ->visible(fn (Refund $record): bool => self::isOfferable($record))
            ->action(function (Refund $record, array $data): void {
                /** @var User $actor */
                $actor = auth()->user();

                try {
                    $approved = app(RefundRequestServiceContract::class)->approve(
                        actor: $actor,
                        refund: $record,
                        notes: $data['notes'] ?? null,
                    );
                } catch (StaffPermissionDeniedException|RefundNotApprovableException $e) {
                    // A refusal is a sentence for the person at the screen.
                    // Anything else is a fault and is left to bubble.
                    Notification::make()
                        ->title('The refund was not approved')
                        ->body($e->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Refund approved')
                    ->body(sprintf(
                        '%s %s is now waiting to be paid out. The money has not left yet.',
                        $approved->currency,
                        $approved->amount,
                    ))
                    ->success()
                    ->send();
            });
    }

    /**
     * What the approver is being asked to agree to, including the §15.1 warning.
     */
    private static function summary(Refund $record): string
    {
        $summary = sprintf(
            '%s %s back to the customer, from the %s that was held. Reason: %s.',
            $record->currency,
            $record->amount,
            $record->amount_paid_at_request,
            lcfirst($record->reason->label()),
        );

        if ($record->admin_fee_was_placeholder) {
            $summary .= ' WARNING: the flat admin fee was an undecided placeholder when this was '
                .'raised, so no fee was deducted. Spec §15.1.';
        }

        return $summary;
    }

    private static function isOfferable(Refund $record): bool
    {
        $user = auth()->user();

        if (! $user instanceof User || ! $user->hasPermissionTo(StaffPermission::RefundsApprove)) {
            return false;
        }

        if (! $record->status->canBeApproved()) {
            return false;
        }

        // Spec §9.3. Not the guard — see the class docblock.
        return (int) $record->requested_by_user_id !== (int) $user->getKey();
    }
}
