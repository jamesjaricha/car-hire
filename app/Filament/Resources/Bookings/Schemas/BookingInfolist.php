<?php

declare(strict_types=1);

namespace App\Filament\Resources\Bookings\Schemas;

use App\Enums\BookingPaymentStatus;
use App\Enums\BookingStatus;
use App\Models\Booking;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * One booking, as staff need to read it.
 *
 * THE TWO DEPOSITS ARE KEPT APART
 *
 * The specification calls conflating them the single most likely misreading, so
 * they are in different sections with their labels spelled out: the booking
 * deposit is part-payment of the hire, the security deposit is refundable cash
 * taken at the counter against damage. A screen that put them side by side
 * under the word "deposit" would recreate the confusion the data model was
 * designed to prevent.
 */
final class BookingInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Booking')
                ->columns(3)
                ->schema([
                    TextEntry::make('reference')->label('Reference')->copyable(),
                    TextEntry::make('status')
                        ->label('Status')
                        ->badge()
                        ->formatStateUsing(fn (BookingStatus $state): string => $state->label()),
                    TextEntry::make('payment_status')
                        ->label('Payment position')
                        ->badge()
                        ->formatStateUsing(fn (BookingPaymentStatus $state): string => $state->label()),
                ]),

            Section::make('Customer')
                ->columns(3)
                ->schema([
                    TextEntry::make('customer.full_name')->label('Name')->placeholder('Not linked'),
                    TextEntry::make('customer.email')->label('Email')->placeholder('—'),
                    TextEntry::make('customer.phone_e164')->label('Phone')->placeholder('—'),
                ]),

            Section::make('Hire')
                ->columns(3)
                ->schema([
                    // The snapshot, not the live vehicle. What the customer
                    // agreed to, immune to later reassignment or rate changes.
                    TextEntry::make('vehicle_registration')->label('Vehicle'),
                    TextEntry::make('vehicle_class_name')->label('Class'),
                    TextEntry::make('chargeable_days')->label('Chargeable days'),
                    TextEntry::make('pickup_at')
                        ->label('Pickup')
                        ->dateTime('j M Y H:i')
                        ->timezone(config('carhire.display_timezone')),
                    TextEntry::make('dropoff_at')
                        ->label('Drop-off')
                        ->dateTime('j M Y H:i')
                        ->timezone(config('carhire.display_timezone')),
                    TextEntry::make('pickupBranch.name')->label('Collecting from'),
                ]),

            Section::make('What the hire costs')
                ->columns(3)
                ->schema([
                    TextEntry::make('hire_total')->label('Hire')->money('ZMW'),
                    TextEntry::make('insurance_total')->label('Damage waiver')->money('ZMW'),
                    TextEntry::make('extras_total')->label('Extras')->money('ZMW'),
                    TextEntry::make('grand_total')->label('Total')->money('ZMW')->weight('bold'),
                    TextEntry::make('amount_paid')->label('Paid')->money('ZMW'),
                    TextEntry::make('balance_due')
                        ->label('Outstanding')
                        ->money('ZMW')
                        ->weight('bold')
                        ->color(fn (Booking $record): ?string => $record->hasOutstandingBalance() ? 'danger' : 'success'),
                ]),

            Section::make('Part-payment of the hire')
                ->description('Paid online to secure the booking. Not the refundable deposit.')
                ->columns(3)
                ->schema([
                    TextEntry::make('booking_deposit_amount')->label('Booking deposit')->money('ZMW'),
                    TextEntry::make('deposit_percentage')->label('Percentage')->suffix('%'),
                    TextEntry::make('payment_deadline_at')
                        ->label('Pay by')
                        ->dateTime('j M Y H:i')
                        ->timezone(config('carhire.display_timezone'))
                        // Null means the customer pays at the counter and no
                        // vehicle was held. Spec §8.2.
                        ->placeholder('At the counter — no vehicle held'),
                ]),

            Section::make('Refundable security deposit')
                ->description('Cash taken at the counter against damage, and returned on a clean return.')
                ->columns(3)
                ->schema([
                    TextEntry::make('security_deposit_amount')->label('Amount')->money('ZMW'),
                    TextEntry::make('security_deposit_collected_at')
                        ->label('Collected')
                        ->dateTime('j M Y H:i')
                        ->timezone(config('carhire.display_timezone'))
                        ->placeholder('Not yet taken'),
                    TextEntry::make('security_deposit_returned_at')
                        ->label('Returned')
                        ->dateTime('j M Y H:i')
                        ->timezone(config('carhire.display_timezone'))
                        ->placeholder('Not yet returned'),
                ]),
        ]);
    }
}
