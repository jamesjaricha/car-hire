<?php

declare(strict_types=1);

namespace App\Filament\Resources\Vehicles\Schemas;

use App\Enums\StaffPermission;
use App\Enums\VehicleStatus;
use App\Models\Operator;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * One physical car.
 *
 * THE TWO MONEY FIELDS ARE THE DANGEROUS PART OF THIS SCREEN
 *
 * `daily_rate` and `security_deposit_amount` are NULLABLE OVERRIDES of the
 * class figures, and null means "inherit". That is not a placeholder in the
 * §15 sense — nobody has to decide it, and inheriting is the normal case for
 * almost every vehicle.
 *
 * What they share with the §15 fields is the failure mode. A required field, or
 * one that coerces empty to zero, would price the hire at ZMW 0.00 rather than
 * at the class rate — and it would do it silently, because a booking at zero
 * looks like a booking. `PricingService` reads the override whenever it is
 * non-null and does not sanity-check it against the class. So empty must reach
 * the database as a real null, exactly as on the class form.
 *
 * They are also the reason this screen needed a permission of its own. A Branch
 * Manager holds `fleet.manage-vehicles` so they can maintain the cars at their
 * branch; `fleet.manage` stays Super Admin because pricing is not a local
 * decision. If editing a vehicle let a manager set its rate, that distinction
 * would be undone through a side door — so both fields are disabled without
 * `fleet.manage`, and disabled fields are not dehydrated, so a submit from a
 * manager cannot clear an override either.
 *
 * PHOTOGRAPHS ARE NOT ONE OF THOSE FIELDS
 *
 * `image_paths` is a nullable vehicle-level override of the class gallery, so
 * it has the same SHAPE as the two money fields and none of their danger. Empty
 * inherits; there is no cast that turns empty into a damaging value; and
 * nothing about it is a pricing decision. It therefore sits under
 * `fleet.manage-vehicles` with the rest of this form — which is also where it
 * belongs practically, since the person who can photograph a car is the manager
 * of the branch it is parked at.
 */
final class VehicleForm
{
    public static function configure(Schema $schema): Schema
    {
        $mayPrice = self::mayPrice();

        return $schema->components([
            Section::make('The vehicle')
                ->columns(2)
                ->schema([
                    Select::make('operator_id')
                        ->label('Operator')
                        ->relationship('operator', 'name')
                        ->default(fn (): ?int => Operator::query()->value('id'))
                        ->required(),

                    TextInput::make('registration')
                        ->label('Registration')
                        ->required()
                        ->maxLength(32)
                        // Two cars cannot share a plate, and staff read this
                        // aloud to match a booking at the counter.
                        ->unique(ignoreRecord: true)
                        ->helperText('Must be unique. This is what staff quote at the counter.'),

                    TextInput::make('make')
                        ->label('Make')
                        ->required()
                        ->maxLength(60),

                    TextInput::make('model')
                        ->label('Model')
                        ->required()
                        ->maxLength(60),

                    TextInput::make('year')
                        ->label('Year')
                        ->numeric()
                        ->minValue(1980)
                        ->maxValue((int) date('Y') + 2)
                        ->helperText('Optional. Shown on the vehicle page as "2021 or similar".'),

                    TextInput::make('colour')
                        ->label('Colour')
                        ->maxLength(40),
                ]),

            Section::make('Specification')
                ->description('Shown to customers as the chips on every vehicle card. Leaving these empty makes the card look unfinished.')
                ->columns(3)
                ->schema([
                    TextInput::make('seats')
                        ->label('Seats')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(60),

                    // Stored as a plain string, offered as a list. The customer
                    // card runs ucfirst() over whatever is here, so a free-text
                    // field puts typos on the shop window.
                    Select::make('transmission')
                        ->label('Transmission')
                        ->options([
                            'manual' => 'Manual',
                            'automatic' => 'Automatic',
                        ]),

                    Select::make('fuel_type')
                        ->label('Fuel')
                        ->options([
                            'petrol' => 'Petrol',
                            'diesel' => 'Diesel',
                            'hybrid' => 'Hybrid',
                            'electric' => 'Electric',
                        ]),
                ]),

            Section::make('Photographs')
                ->description('Pictures of THIS car. Leave empty and it shows its class\'s photographs instead — customers are told when that is what they are looking at.')
                ->schema([
                    FileUpload::make('image_paths')
                        ->label('Images')
                        ->image()
                        ->multiple()
                        ->reorderable()
                        ->appendFiles()
                        ->imageEditor()
                        // A directory of its own so a vehicle's photographs are
                        // never confused with a class's on disk — the two are
                        // deleted, replaced and audited on different schedules.
                        ->directory('vehicles')
                        // The public disk: these are shown to anonymous
                        // visitors in search results. Requires
                        // `php artisan storage:link` once per environment.
                        ->disk('public')
                        ->maxFiles(6)
                        // 4 MB. `.user.ini` raises PHP's own limit to 8 MB so
                        // an oversized phone photograph meets this message
                        // rather than a raw nginx 413.
                        ->maxSize(4096)
                        ->helperText(
                            'The first image is the one used on cards. Drag to reorder. '
                            .'A photograph of the actual car is the single biggest thing that makes a '
                            .'customer trust a booking — they are hiring this registration, not one like it.'
                        ),
                ]),

            Section::make('Where it lives, and whether it is on the road')
                ->columns(3)
                ->schema([
                    Select::make('vehicle_class_id')
                        ->label('Class')
                        ->relationship('vehicleClass', 'name')
                        ->required()
                        ->searchable()
                        ->preload()
                        ->helperText('The class carries the price, the waiver and the deposit. A class still awaiting a pricing decision is withheld from search, and so is every vehicle in it.'),

                    Select::make('branch_id')
                        ->label('Branch')
                        ->relationship('branch', 'name')
                        ->required()
                        ->searchable()
                        ->preload()
                        ->helperText('Where the vehicle currently is. One-way hires are arranged by staff, so this is moved by hand after a one-way return.'),

                    Select::make('status')
                        ->label('Status')
                        ->options(self::statusOptions())
                        ->default(VehicleStatus::Available->value)
                        ->required()
                        ->helperText('Only "Available" can be booked. Vehicles are retired, never deleted — a booking\'s history reads through its vehicle.'),
                ]),

            Section::make('Price overrides')
                ->description($mayPrice
                    ? 'Leave both empty unless this particular vehicle is priced differently from the rest of its class. Empty means it inherits the class figure — it does not mean zero.'
                    : 'Set by a Super Admin. Shown here so you can see whether this vehicle is priced separately from its class.')
                ->columns(2)
                ->schema([
                    self::inheritableMoney('daily_rate', 'Daily rate', $mayPrice)
                        ->helperText('Overrides the class daily rate for this vehicle only. Existing bookings keep the rate they were sold at.'),

                    self::inheritableMoney('security_deposit_amount', 'Refundable security deposit', $mayPrice)
                        ->helperText('Overrides the class deposit. A vehicle-level figure here also rescues a class whose own deposit is still undecided.'),

                    Textarea::make('notes')
                        ->label('Internal notes')
                        ->rows(2)
                        ->maxLength(1000)
                        ->helperText('Never shown to customers.')
                        ->columnSpanFull(),
                ]),
        ]);
    }

    /**
     * A money field where empty means "inherit from the class", not zero.
     *
     * The `dehydrateStateUsing` is the load-bearing line. Filament hands back an
     * empty string for a cleared numeric input, and an empty string through the
     * `decimal:2` cast becomes 0.00 — which `PricingService` would read as a
     * deliberate override and quote the hire at nothing.
     */
    private static function inheritableMoney(string $name, string $label, bool $mayPrice): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->prefix('ZMW')
            ->numeric()
            ->minValue(0)
            ->nullable()
            ->placeholder('Inherits the class figure')
            ->disabled(! $mayPrice)
            // Explicit rather than relying on the default for a disabled field.
            // If this ever dehydrated for someone without the permission, their
            // submit would write null and silently clear a Super Admin's
            // override — a price change made by somebody who cannot make one.
            ->dehydrated($mayPrice)
            ->dehydrateStateUsing(
                fn (mixed $state): ?string => $state === null || $state === '' ? null : (string) $state
            );
    }

    /**
     * Whether the signed-in user may set a vehicle-level price.
     */
    private static function mayPrice(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->hasPermissionTo(StaffPermission::FleetManage);
    }

    /**
     * @return array<string, string>
     */
    private static function statusOptions(): array
    {
        $options = [];

        foreach (VehicleStatus::cases() as $status) {
            $options[$status->value] = $status->label();
        }

        return $options;
    }
}
