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
 * A small sample fleet so the application has something to show during
 * development.
 *
 * EVERY FIGURE HERE IS A PLACEHOLDER. Daily rates, insurance prices, excesses
 * and security deposits are invented. Spec §15 items 2, 3, 4 and 8 — the real
 * per-class deposits, insurance pricing and branch list — are outstanding, and
 * are tracked in docs/OPEN-ITEMS.md.
 *
 * Local environment only.
 */
final class DemoFleetSeeder extends Seeder
{
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

        $lusaka = $this->branch($operator, 'Lusaka', 'LUS01');
        $livingstone = $this->branch($operator, 'Livingstone', 'LVI01');

        $economy = $this->vehicleClass($operator, 'Economy', '650.00', '90.00', '4000.00', '1500.00', 0);
        $suv = $this->vehicleClass($operator, 'SUV', '1450.00', '180.00', '8000.00', '4000.00', 1);
        $doubleCab = $this->vehicleClass($operator, 'Double Cab 4x4', '1900.00', '240.00', '12000.00', '6000.00', 2, 240);

        $this->vehicle($operator, $economy, $lusaka, 'ABC 1101', 'Toyota', 'Corolla', 2021);
        $this->vehicle($operator, $economy, $lusaka, 'ABC 1102', 'Mazda', 'Demio', 2020);
        $this->vehicle($operator, $suv, $lusaka, 'ABC 2201', 'Nissan', 'X-Trail', 2022);
        $this->vehicle($operator, $doubleCab, $lusaka, 'ABC 3301', 'Toyota', 'Hilux', 2023);
        $this->vehicle($operator, $suv, $livingstone, 'ABC 2202', 'Toyota', 'Fortuner', 2022);
        $this->vehicle(
            $operator, $economy, $livingstone, 'ABC 1103', 'Toyota', 'Vitz', 2019,
            status: VehicleStatus::Maintenance,
        );
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

    private function vehicleClass(
        Operator $operator,
        string $name,
        string $dailyRate,
        string $insurancePrice,
        string $excess,
        string $securityDeposit,
        int $order,
        int $bufferMinutes = 120,
    ): VehicleClass {
        return VehicleClass::query()->firstOrCreate(
            ['operator_id' => $operator->getKey(), 'slug' => str($name)->slug()->value()],
            [
                'name' => $name,
                'description' => null,
                'daily_rate' => $dailyRate,
                'insurance_price' => $insurancePrice,
                'insurance_price_mode' => InsurancePriceMode::PerDay,
                'insurance_excess_amount' => $excess,
                'security_deposit_amount' => $securityDeposit,
                'turnaround_buffer_minutes' => $bufferMinutes,
                'display_order' => $order,
                'is_active' => true,
            ],
        );
    }

    private function vehicle(
        Operator $operator,
        VehicleClass $class,
        Branch $branch,
        string $registration,
        string $make,
        string $model,
        int $year,
        VehicleStatus $status = VehicleStatus::Available,
    ): Vehicle {
        return Vehicle::query()->firstOrCreate(
            ['registration' => $registration],
            [
                'operator_id' => $operator->getKey(),
                'vehicle_class_id' => $class->getKey(),
                'branch_id' => $branch->getKey(),
                'make' => $make,
                'model' => $model,
                'year' => $year,
                'colour' => 'white',
                'transmission' => 'manual',
                'fuel_type' => 'petrol',
                'seats' => 5,
                // Null = inherit the class rate. Overrides are the exception.
                'daily_rate' => null,
                'security_deposit_amount' => null,
                'status' => $status,
                'notes' => null,
            ],
        );
    }
}
