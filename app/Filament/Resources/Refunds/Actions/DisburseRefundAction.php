<?php

declare(strict_types=1);

namespace App\Filament\Resources\Refunds\Actions;

use App\Contracts\RefundDisbursementServiceContract;
use App\Enums\StaffPermission;
use App\Exceptions\RefundNotDisbursableException;
use App\Exceptions\StaffPermissionDeniedException;
use App\Models\Refund;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * The money actually leaving. Spec §9.3's third step.
 *
 * THERE IS NO AMOUNT FIELD, AND THAT IS THE DESIGN
 *
 * The figure was computed from §9, frozen when the refund was raised, and
 * approved by a second person. Offering an editable amount here would let the
 * person handing over cash pay a different sum from the one that was authorised,
 * and the approval would still read as covering it.
 *
 * The reference is required and the modal says why. §9.3 wants proof of
 * disbursement — a signed cash receipt number, a transfer reference, a MoMo
 * transaction ID. If there is nothing to type, the money has not actually left,
 * and recording that it has is worse than recording nothing.
 *
 * ->requiresConfirmation() is deliberately NOT used. A second "are you sure"
 * on top of a modal that already asks for a reference trains people to click
 * through both. The reference IS the confirmation: you cannot supply one without
 * having done the thing.
 */
final class DisburseRefundAction
{
    public static function make(string $name = 'disburseRefund'): Action
    {
        return Action::make($name)
            ->label('Record payout')
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('warning')
            ->modalHeading('Record that the money has gone back')
            ->modalDescription(fn (Refund $record): string => sprintf(
                'Only use this once %s %s has actually left, by %s. This cannot be undone, and the '
                .'same refund can never be paid out twice.',
                $record->currency,
                $record->amount,
                lcfirst($record->method->label()),
            ))
            ->modalSubmitActionLabel('Record payout')
            ->schema([
                TextInput::make('reference')
                    ->label('Proof of payment')
                    ->required()
                    ->maxLength(100)
                    ->helperText('The cash receipt number, bank transfer reference, or mobile money transaction ID. Spec §9.3.'),

                Textarea::make('notes')
                    ->label('Notes')
                    ->rows(2)
                    ->maxLength(500),
            ])
            ->visible(fn (Refund $record): bool => self::isOfferable($record))
            ->action(function (Refund $record, array $data): void {
                /** @var User $actor */
                $actor = auth()->user();

                try {
                    $result = app(RefundDisbursementServiceContract::class)->disburse(
                        actor: $actor,
                        refund: $record,
                        disbursementReference: (string) $data['reference'],
                        notes: $data['notes'] ?? null,
                    );
                } catch (StaffPermissionDeniedException|RefundNotDisbursableException $e) {
                    // Nothing was written: the service runs in one transaction,
                    // so a refusal here leaves no disbursement behind. That
                    // matters more here than anywhere else in the panel — a
                    // half-written payout is money the records cannot account
                    // for.
                    Notification::make()
                        ->title('No payout was recorded')
                        ->body($e->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Payout recorded')
                    ->body(sprintf(
                        '%s %s returned, reference %s. The booking is now recorded as having been paid %s.',
                        $result->refund->currency,
                        $result->disbursement->amount_disbursed,
                        $result->disbursement->disbursement_reference,
                        $result->amountPaid,
                    ))
                    ->success()
                    ->send();
            });
    }

    private static function isOfferable(Refund $record): bool
    {
        $user = auth()->user();

        // §12 names no permission for handing the money over, so this requires
        // the one it does name for authorising it. See RefundDisbursementService
        // and docs/OPEN-ITEMS.md — the operator may want clerks to do this.
        if (! $user instanceof User || ! $user->hasPermissionTo(StaffPermission::RefundsApprove)) {
            return false;
        }

        return $record->status->canBeDisbursed();
    }
}
