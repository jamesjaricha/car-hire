<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\InsurancePriceMode;
use App\Enums\VehicleStatus;
use App\Models\Branch;
use App\Models\Operator;
use App\Models\Vehicle;
use App\Models\VehicleClass;
use Illuminate\Database\Seeder;

/**
 * A sample fleet so the application has something to show during development
 * and in a demonstration.
 *
 * EVERY FIGURE HERE IS INVENTED. Daily rates, waiver prices, excesses and
 * security deposits are plausible for the Zambian market and nothing more. Spec
 * §15 items 2, 3, 4 and 8 — the real per-class deposits, waiver pricing and
 * branch list — are outstanding and tracked in docs/OPEN-ITEMS.md. Nothing here
 * is a decision the business has made.
 *
 * WHY THE FLEET IS THIS SIZE
 *
 * The home page shows one vehicle per class and the results page groups by
 * class, so a three-class fleet renders as three cards in a four-column grid —
 * which reads as an unfinished page rather than a small operator. Six classes
 * across two branches fills the row, gives the results page something to group,
 * and lets the vehicle counts on the cards ("3 available") be real.
 *
 * EVERY CLASS IS DELIBERATELY FULLY PRICED
 *
 * A class holding a null in any of the three §15 columns is withheld from
 * search entirely and never reaches the home page — see VehicleClass and
 * ARCHITECTURE §14. That is correct behaviour and must not be loosened, but it
 * means a demo fleet with a missing figure silently shows fewer cars than
 * expected with nothing to say why. DemoFleetSeederTest asserts all six are
 * sellable, so adding a seventh without its figures fails a test rather than
 * quietly shrinking the shop window.
 *
 * IDEMPOTENT, AND THAT MATTERS FOR PHOTOGRAPHS
 *
 * Every write is firstOrCreate keyed on something stable — slug for a class,
 * registration for a vehicle. Re-running never overwrites what somebody has
 * since edited in the panel, which includes uploaded photographs sitting in
 * `image_paths`. `migrate:fresh --seed` does drop them, because that drops the
 * table; a plain `db:seed` does not.
 *
 * Local environment only, called under a guard in DatabaseSeeder. Unlike
 * DemoStaffSeeder this does not throw elsewhere: sample cars with invented
 * prices are embarrassing in production, not dangerous, and the test suite
 * needs to be able to call it.
 */
final class DemoFleetSeeder extends Seeder
{
    /**
     * The branches. Operating hours are placeholders — spec §15.8.
     *
     * @var list<array{code: string, city: string}>
     */
    private const BRANCHES = [
        ['code' => 'LUS01', 'city' => 'Lusaka'],
        ['code' => 'LVI01', 'city' => 'Livingstone'],
    ];

    /**
     * Slugs are set explicitly rather than derived by a model hook, because
     * DatabaseSeeder runs under WithoutModelEvents and a hook would never
     * fire — the trap recorded in DatabaseSeeder's own docblock.
     *
     * `buffer` is the turnaround minutes: longer for vehicles that come back
     * from gravel needing more than a wash.
     *
     * @var list<array{slug: string, name: string, description: string, rate: string, waiver: string, excess: string, deposit: string, order: int, buffer: int}>
     */
    private const CLASSES = [
        [
            'slug' => 'economy',
            'name' => 'Economy',
            'description' => 'Small automatic hatchbacks and saloons for town driving. Easy to park, cheapest to run.',
            'rate' => '650.00', 'waiver' => '90.00', 'excess' => '4000.00', 'deposit' => '1500.00',
            'order' => 0, 'buffer' => 120,
        ],
        [
            'slug' => 'suv',
            'name' => 'SUV',
            'description' => 'Higher ground clearance for gravel and farm roads, without needing full four-wheel drive.',
            'rate' => '1450.00', 'waiver' => '180.00', 'excess' => '8000.00', 'deposit' => '4000.00',
            'order' => 1, 'buffer' => 120,
        ],
        [
            'slug' => 'double-cab-4x4',
            'name' => 'Double Cab 4x4',
            'description' => 'Five seats and an open load bed. The standard choice for site work and long rural trips.',
            'rate' => '1900.00', 'waiver' => '240.00', 'excess' => '12000.00', 'deposit' => '6000.00',
            'order' => 2, 'buffer' => 240,
        ],
        [
            'slug' => 'executive',
            'name' => 'Executive',
            'description' => 'Full-size automatic saloons for airport collections and corporate visitors.',
            'rate' => '2200.00', 'waiver' => '280.00', 'excess' => '14000.00', 'deposit' => '7000.00',
            'order' => 3, 'buffer' => 120,
        ],
        [
            'slug' => 'safari-4x4',
            'name' => 'Safari 4x4',
            'description' => 'Long-range four-wheel drive for national parks and unsurfaced roads. Dual fuel tanks.',
            'rate' => '2850.00', 'waiver' => '340.00', 'excess' => '18000.00', 'deposit' => '9000.00',
            'order' => 4, 'buffer' => 240,
        ],
        [
            'slug' => 'minibus',
            'name' => 'Minibus',
            'description' => 'Fourteen seats for group transfers, conferences and tour parties.',
            'rate' => '2400.00', 'waiver' => '300.00', 'excess' => '15000.00', 'deposit' => '7500.00',
            'order' => 5, 'buffer' => 240,
        ],
    ];

    /**
     * The physical fleet. Weighted so Livingstone carries the tourism vehicles
     * and Lusaka the corporate and town ones, because a demonstration that
     * shows both branches returning identical results does not show much.
     *
     * One vehicle is in maintenance on purpose: it must not appear in a search,
     * and seeding it makes that checkable in a browser rather than only in a
     * test.
     *
     * `rate` and `deposit` are left off every row, so each vehicle inherits its
     * class — the normal case. A single override is seeded to prove the chain
     * resolves; see the Land Cruiser.
     *
     * @var list<array{reg: string, class: string, branch: string, make: string, model: string, year: int, colour: string, transmission: string, fuel: string, seats: int, status?: string, rate?: string}>
     */
    private const VEHICLES = [
        // Economy
        ['reg' => 'ABC 1101', 'class' => 'economy', 'branch' => 'LUS01', 'make' => 'Toyota', 'model' => 'Corolla', 'year' => 2021, 'colour' => 'white', 'transmission' => 'automatic', 'fuel' => 'petrol', 'seats' => 5],
        ['reg' => 'ABC 1102', 'class' => 'economy', 'branch' => 'LUS01', 'make' => 'Mazda', 'model' => 'Demio', 'year' => 2020, 'colour' => 'silver', 'transmission' => 'automatic', 'fuel' => 'petrol', 'seats' => 5],
        ['reg' => 'ABC 1104', 'class' => 'economy', 'branch' => 'LUS01', 'make' => 'Honda', 'model' => 'Fit', 'year' => 2020, 'colour' => 'white', 'transmission' => 'automatic', 'fuel' => 'petrol', 'seats' => 5],
        ['reg' => 'ABC 1105', 'class' => 'economy', 'branch' => 'LVI01', 'make' => 'Toyota', 'model' => 'Corolla', 'year' => 2022, 'colour' => 'grey', 'transmission' => 'automatic', 'fuel' => 'petrol', 'seats' => 5],
        // Off the road, deliberately. Must not appear in any search.
        ['reg' => 'ABC 1103', 'class' => 'economy', 'branch' => 'LVI01', 'make' => 'Toyota', 'model' => 'Vitz', 'year' => 2019, 'colour' => 'blue', 'transmission' => 'automatic', 'fuel' => 'petrol', 'seats' => 5, 'status' => 'maintenance'],

        // SUV
        ['reg' => 'ABC 2201', 'class' => 'suv', 'branch' => 'LUS01', 'make' => 'Nissan', 'model' => 'X-Trail', 'year' => 2022, 'colour' => 'black', 'transmission' => 'automatic', 'fuel' => 'petrol', 'seats' => 5],
        ['reg' => 'ABC 2203', 'class' => 'suv', 'branch' => 'LUS01', 'make' => 'Mazda', 'model' => 'CX-5', 'year' => 2021, 'colour' => 'red', 'transmission' => 'automatic', 'fuel' => 'diesel', 'seats' => 5],
        ['reg' => 'ABC 2202', 'class' => 'suv', 'branch' => 'LVI01', 'make' => 'Toyota', 'model' => 'Fortuner', 'year' => 2022, 'colour' => 'white', 'transmission' => 'automatic', 'fuel' => 'diesel', 'seats' => 7],
        ['reg' => 'ABC 2204', 'class' => 'suv', 'branch' => 'LVI01', 'make' => 'Mitsubishi', 'model' => 'Pajero', 'year' => 2020, 'colour' => 'silver', 'transmission' => 'automatic', 'fuel' => 'diesel', 'seats' => 7],

        // Double Cab 4x4
        ['reg' => 'ABC 3301', 'class' => 'double-cab-4x4', 'branch' => 'LUS01', 'make' => 'Toyota', 'model' => 'Hilux', 'year' => 2023, 'colour' => 'white', 'transmission' => 'manual', 'fuel' => 'diesel', 'seats' => 5],
        ['reg' => 'ABC 3302', 'class' => 'double-cab-4x4', 'branch' => 'LUS01', 'make' => 'Isuzu', 'model' => 'D-Max', 'year' => 2022, 'colour' => 'grey', 'transmission' => 'manual', 'fuel' => 'diesel', 'seats' => 5],
        ['reg' => 'ABC 3303', 'class' => 'double-cab-4x4', 'branch' => 'LVI01', 'make' => 'Toyota', 'model' => 'Hilux', 'year' => 2021, 'colour' => 'white', 'transmission' => 'manual', 'fuel' => 'diesel', 'seats' => 5],

        // Executive
        ['reg' => 'ABC 4401', 'class' => 'executive', 'branch' => 'LUS01', 'make' => 'Toyota', 'model' => 'Camry', 'year' => 2022, 'colour' => 'black', 'transmission' => 'automatic', 'fuel' => 'petrol', 'seats' => 5],
        ['reg' => 'ABC 4402', 'class' => 'executive', 'branch' => 'LUS01', 'make' => 'Nissan', 'model' => 'Teana', 'year' => 2021, 'colour' => 'silver', 'transmission' => 'automatic', 'fuel' => 'petrol', 'seats' => 5],

        // Safari 4x4. The Land Cruiser carries a rate override: it is the
        // newest and most in demand unit in its class, and a seeded override
        // proves PricingService resolves vehicle before class.
        ['reg' => 'ABC 5501', 'class' => 'safari-4x4', 'branch' => 'LVI01', 'make' => 'Toyota', 'model' => 'Land Cruiser', 'year' => 2021, 'colour' => 'white', 'transmission' => 'manual', 'fuel' => 'diesel', 'seats' => 5, 'rate' => '3200.00'],
        ['reg' => 'ABC 5502', 'class' => 'safari-4x4', 'branch' => 'LVI01', 'make' => 'Toyota', 'model' => 'Land Cruiser Prado', 'year' => 2022, 'colour' => 'beige', 'transmission' => 'automatic', 'fuel' => 'diesel', 'seats' => 7],

        // Minibus
        ['reg' => 'ABC 6601', 'class' => 'minibus', 'branch' => 'LUS01', 'make' => 'Toyota', 'model' => 'Hiace', 'year' => 2021, 'colour' => 'white', 'transmission' => 'manual', 'fuel' => 'diesel', 'seats' => 14],
        ['reg' => 'ABC 6602', 'class' => 'minibus', 'branch' => 'LVI01', 'make' => 'Toyota', 'model' => 'Quantum', 'year' => 2022, 'colour' => 'white', 'transmission' => 'manual', 'fuel' => 'diesel', 'seats' => 14],
    ];

    public function run(): void
    {
        $operator = Operator::query()->firstOrCreate(
            ['slug' => 'house-fleet'],
            [
                'name' => 'House Fleet',
                'contact_email' => null,
                'contact_phone_e164' => null,
                'is_active' => true,
            ],
        );

        $branches = [];

        foreach (self::BRANCHES as $row) {
            $branches[$row['code']] = $this->branch($operator, $row['city'], $row['code']);
        }

        $classes = [];

        foreach (self::CLASSES as $row) {
            $classes[$row['slug']] = $this->vehicleClass($operator, $row);
        }

        foreach (self::VEHICLES as $row) {
            $this->vehicle(
                $operator,
                $classes[$row['class']],
                $branches[$row['branch']],
                $row,
            );
        }
    }

    private function branch(Operator $operator, string $city, string $code): Branch
    {
        return Branch::query()->firstOrCreate(
            ['operator_id' => $operator->getKey(), 'code' => $code],
            [
                'name' => $city.' Branch',
                'city' => $city,
                'address' => null,
                'phone_e164' => null,
                // Operating hours are a placeholder — spec §15.8.
                'opens_at' => '08:00:00',
                'closes_at' => '17:00:00',
                'after_hours_pickup' => false,
                'is_active' => true,
            ],
        );
    }

    /**
     * @param  array{slug: string, name: string, description: string, rate: string, waiver: string, excess: string, deposit: string, order: int, buffer: int}  $row
     */
    private function vehicleClass(Operator $operator, array $row): VehicleClass
    {
        return VehicleClass::query()->firstOrCreate(
            ['operator_id' => $operator->getKey(), 'slug' => $row['slug']],
            [
                'name' => $row['name'],
                'description' => $row['description'],
                'daily_rate' => $row['rate'],
                // All three §15 figures are supplied, so every seeded class is
                // sellable. See the class docblock — a null here removes the
                // class from search with nothing on screen to explain it.
                'insurance_price' => $row['waiver'],
                'insurance_price_mode' => InsurancePriceMode::PerDay,
                'insurance_excess_amount' => $row['excess'],
                'security_deposit_amount' => $row['deposit'],
                'turnaround_buffer_minutes' => $row['buffer'],
                'display_order' => $row['order'],
                'is_active' => true,
            ],
        );
    }

    /**
     * @param  array{reg: string, make: string, model: string, year: int, colour: string, transmission: string, fuel: string, seats: int, status?: string, rate?: string}  $row
     */
    private function vehicle(Operator $operator, VehicleClass $class, Branch $branch, array $row): Vehicle
    {
        return Vehicle::query()->firstOrCreate(
            ['registration' => $row['reg']],
            [
                'operator_id' => $operator->getKey(),
                'vehicle_class_id' => $class->getKey(),
                'branch_id' => $branch->getKey(),
                'make' => $row['make'],
                'model' => $row['model'],
                'year' => $row['year'],
                'colour' => $row['colour'],
                'transmission' => $row['transmission'],
                'fuel_type' => $row['fuel'],
                'seats' => $row['seats'],
                // Null = inherit the class rate. Overrides are the exception.
                'daily_rate' => $row['rate'] ?? null,
                'security_deposit_amount' => null,
                'status' => VehicleStatus::from($row['status'] ?? 'available'),
                'notes' => null,
            ],
        );
    }
}
