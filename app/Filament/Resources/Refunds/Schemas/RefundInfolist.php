<?php

declare(strict_types=1);

namespace App\Filament\Resources\Refunds\Schemas;

use App\Enums\RefundReason;
use App\Enums\RefundStatus;
use App\Models\Refund;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * One refund, as the person about to approve it needs to read it.
 *
 * THE WORKING IS SHOWN, NOT JUST THE ANSWER
 *
 * A refund is a figure somebody will have to justify to a customer on the
 * telephone. "You paid K2,310, we kept the K1,155 deposit because you cancelled
 * inside 24 hours, and the admin fee is K150" is the conversation; a screen
 * showing only "K1,005" makes it impossible to have from the record.
 *
 * It also matters at approval. §9.3's second person is there to check something,
 * and they cannot check a number with no derivation attached.
 */
final class RefundInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Refund')
                ->columns(3)
                ->schema([
                    TextEntry::make('booking.reference')->label('Booking')->copyable(),
                    TextEntry::make('status')
                        ->label('Status')
                        ->badge()
                        ->formatStateUsing(fn (RefundStatus $state): string => $state->label()),
                    TextEntry::make('reason')
                        ->label('Why')
                        ->badge()
                        ->formatStateUsing(fn (RefundReason $state): string => $state->label()),
                    TextEntry::make('reason')
                        ->key('reason_rule')
                        ->label('The rule being applied')
                        ->columnSpanFull()
                        ->formatStateUsing(fn (RefundReason $state): string => $state->description()),
                ]),

            Section::make('How the figure was reached')
                ->description('Computed from spec §9 when the refund was raised, and frozen. Staff cannot edit it.')
                ->columns(3)
                ->schema([
                    TextEntry::make('amount_paid_at_request')
                        ->label('Money held')
                        ->money('ZMW'),

                    TextEntry::make('booking_deposit_retained')
                        ->label('Booking deposit withheld')
                        ->money('ZMW')
                        // Null would read as "no rule applied"; zero with a note
                        // says the rule was applied and came to nothing.
                        ->helperText(fn (Refund $record): string => $record->reason->isTimingSensitive()
                            ? 'Non-refundable inside 24 hours of pickup. Spec §9.1.'
                            : 'Not withheld for this reason.'),

                    TextEntry::make('admin_fee_deducted')
                        ->label('Admin fee')
                        ->money('ZMW')
                        ->color(fn (Refund $record): ?string => $record->admin_fee_was_placeholder ? 'warning' : null),

                    TextEntry::make('amount')
                        ->label('Refund due')
                        ->money('ZMW')
                        ->weight('bold')
                        ->size('lg')
                        ->columnSpanFull(),

                    // THE §15.1 WARNING. Placed with the figure it affects
                    // rather than at the top of the page, so it cannot be
                    // scrolled past on the way to the amount.
                    TextEntry::make('admin_fee_was_placeholder')
                        ->key('placeholder_warning')
                        ->label('Warning')
                        ->columnSpanFull()
                        ->color('warning')
                        ->weight('bold')
                        ->visible(fn (Refund $record): bool => $record->admin_fee_was_placeholder)
                        ->state(fn (Refund $record): string => sprintf(
                            'The flat admin fee was still an undecided placeholder when this refund was '
                            .'raised, so %s was deducted. Spec §15.1 requires the business to set a real '
                            .'figure and publish it in the T&Cs before taking real money. '
                            .'Setting: admin_fee_amount.',
                            $record->admin_fee_deducted,
                        )),

                    TextEntry::make('admin_fee_configured')
                        ->key('fee_clamped')
                        ->label('Note')
                        ->columnSpanFull()
                        ->color('gray')
                        // Only shown where it explains something: the fee was
                        // reduced because there was not enough left to take it
                        // from. The customer is not billed the difference.
                        ->visible(fn (Refund $record): bool => bccomp(
                            (string) $record->admin_fee_deducted,
                            (string) $record->admin_fee_configured,
                            2,
                        ) < 0)
                        ->state(fn (Refund $record): string => sprintf(
                            'The configured admin fee is %s, but only %s remained after the withheld '
                            .'deposit, so that is all that was taken. The difference is not billed.',
                            $record->admin_fee_configured,
                            $record->admin_fee_deducted,
                        )),
                ]),

            Section::make('Who decided')
                ->description('Spec §9.3 requires the person who approves a refund to be someone other than the person who requested it.')
                ->columns(3)
                ->schema([
                    TextEntry::make('requestedBy.name')->label('Raised by'),
                    TextEntry::make('requested_at')
                        ->label('Raised')
                        ->dateTime('j M Y H:i')
                        ->timezone(config('carhire.display_timezone')),
                    TextEntry::make('method')
                        ->label('Paying back by')
                        ->badge(),

                    TextEntry::make('approvedBy.name')
                        ->label('Approved by')
                        ->placeholder('Not yet approved'),
                    TextEntry::make('approved_at')
                        ->label('Approved')
                        ->dateTime('j M Y H:i')
                        ->timezone(config('carhire.display_timezone'))
                        ->placeholder('—'),
                    TextEntry::make('notes')
                        ->label('Notes')
                        ->placeholder('—'),

                    TextEntry::make('rejectedBy.name')
                        ->label('Rejected by')
                        ->visible(fn (Refund $record): bool => $record->status === RefundStatus::Rejected),
                    TextEntry::make('rejected_at')
                        ->label('Rejected')
                        ->dateTime('j M Y H:i')
                        ->timezone(config('carhire.display_timezone'))
                        ->visible(fn (Refund $record): bool => $record->status === RefundStatus::Rejected),
                    TextEntry::make('rejection_reason')
                        ->label('Reason given')
                        ->visible(fn (Refund $record): bool => $record->status === RefundStatus::Rejected),
                ]),

            Section::make('Payout')
                ->description('Spec §9.3 requires proof that the money actually left.')
                ->columns(3)
                ->visible(fn (Refund $record): bool => $record->isDisbursed())
                ->schema([
                    TextEntry::make('disbursement.disbursement_reference')
                        ->label('Reference')
                        ->copyable(),
                    TextEntry::make('disbursement.amount_disbursed')
                        ->label('Paid')
                        ->money('ZMW'),
                    TextEntry::make('disbursement.disbursed_at')
                        ->label('Paid on')
                        ->dateTime('j M Y H:i')
                        ->timezone(config('carhire.display_timezone')),
                    TextEntry::make('disbursement.disbursedBy.name')->label('Paid by'),
                    TextEntry::make('disbursement.branch.name')
                        ->label('At')
                        ->placeholder('No branch'),
                    TextEntry::make('disbursement.notes')
                        ->label('Notes')
                        ->placeholder('—'),
                ]),
        ]);
    }
}
