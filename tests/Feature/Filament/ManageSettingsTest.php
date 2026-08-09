<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Contracts\SettingsRepositoryContract;
use App\Enums\SettingKey;
use App\Enums\StaffRole;
use App\Filament\Pages\ManageSettings;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The screen that finally lets the business answer spec §15.
 *
 * The test that matters most here is the one about placeholder flags. Everything
 * else on this page is a form; that one is the mechanism which keeps
 * OPEN-ITEMS.md honest, and it fails silently if it breaks — the warnings simply
 * stop appearing, and every figure looks decided.
 */
final class ManageSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SettingsSeeder::class);

        app(SettingsRepositoryContract::class)->flush();
    }

    // --- Who may open it ----------------------------------------------------

    public function test_a_super_admin_may_open_it(): void
    {
        $this->actingAs(User::factory()->withRole(StaffRole::SuperAdmin)->create())
            ->get(ManageSettings::getUrl())
            ->assertSuccessful();
    }

    /**
     * Not from §12, which has no permission for configuration at all. Settled
     * with the operator 2026-08-09: Super Admin only, because these values
     * decide what every customer is charged.
     */
    public function test_a_branch_manager_may_not(): void
    {
        $this->actingAs(User::factory()->withRole(StaffRole::BranchManager)->create())
            ->get(ManageSettings::getUrl())
            ->assertForbidden();
    }

    public function test_it_is_hidden_from_the_navigation_for_anyone_else(): void
    {
        $this->actingAs(User::factory()->withRole(StaffRole::CounterClerk)->create());

        $this->assertFalse(ManageSettings::canAccess());
    }

    // --- Saving -------------------------------------------------------------

    public function test_it_saves_an_edited_value(): void
    {
        Livewire::actingAs(User::factory()->withRole(StaffRole::SuperAdmin)->create())
            ->test(ManageSettings::class)
            ->set('data.'.SettingKey::AdminFeeAmount->value, '150.00')
            ->call('save')
            ->assertHasNoErrors();

        app(SettingsRepositoryContract::class)->flush();

        $this->assertSame(
            '150.00',
            app(SettingsRepositoryContract::class)->decimal(SettingKey::AdminFeeAmount),
        );
    }

    /**
     * THE IMPORTANT ONE.
     *
     * `SettingsRepository::set()` defaults `isPlaceholder` to false, so a naive
     * save-everything loop would mark all seventeen settings as decided the
     * first time anybody pressed Save — including the twelve nobody looked at.
     * That would silence every warning in the panel and leave OPEN-ITEMS.md
     * describing a state the database no longer reports.
     */
    public function test_saving_clears_the_placeholder_flag_only_for_fields_that_changed(): void
    {
        $this->assertTrue(Setting::query()->where('key', SettingKey::AdminFeeAmount->value)->value('is_placeholder'));
        $this->assertTrue(Setting::query()->where('key', SettingKey::FuelPolicy->value)->value('is_placeholder'));

        Livewire::actingAs(User::factory()->withRole(StaffRole::SuperAdmin)->create())
            ->test(ManageSettings::class)
            ->set('data.'.SettingKey::AdminFeeAmount->value, '150.00')
            ->call('save')
            ->assertHasNoErrors();

        // Edited: now a decision.
        $this->assertFalse(
            (bool) Setting::query()->where('key', SettingKey::AdminFeeAmount->value)->value('is_placeholder')
        );

        // Untouched: still a placeholder, still warning.
        $this->assertTrue(
            (bool) Setting::query()->where('key', SettingKey::FuelPolicy->value)->value('is_placeholder')
        );
    }

    /**
     * Pressing Save without editing anything must change nothing at all — this
     * is the shape the previous test guards against, arrived at from the other
     * direction.
     */
    public function test_saving_without_editing_anything_clears_no_flags(): void
    {
        $before = Setting::query()->where('is_placeholder', true)->count();

        Livewire::actingAs(User::factory()->withRole(StaffRole::SuperAdmin)->create())
            ->test(ManageSettings::class)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($before, Setting::query()->where('is_placeholder', true)->count());
    }

    public function test_it_saves_a_toggle(): void
    {
        Livewire::actingAs(User::factory()->withRole(StaffRole::SuperAdmin)->create())
            ->test(ManageSettings::class)
            ->set('data.'.SettingKey::CounterClerkMayConfirmCash->value, true)
            ->call('save')
            ->assertHasNoErrors();

        app(SettingsRepositoryContract::class)->flush();

        $this->assertTrue(
            app(SettingsRepositoryContract::class)->boolean(SettingKey::CounterClerkMayConfirmCash)
        );
    }

    /**
     * The deposit percentage decides what every customer is asked to pay. A
     * free-text settings editor would take anything; this screen does not.
     */
    public function test_it_refuses_a_deposit_percentage_above_one_hundred(): void
    {
        Livewire::actingAs(User::factory()->withRole(StaffRole::SuperAdmin)->create())
            ->test(ManageSettings::class)
            ->set('data.'.SettingKey::BookingDepositPercentage->value, '150')
            ->call('save')
            ->assertHasErrors();

        app(SettingsRepositoryContract::class)->flush();

        $this->assertSame(50, app(SettingsRepositoryContract::class)->integer(SettingKey::BookingDepositPercentage));
    }
}
