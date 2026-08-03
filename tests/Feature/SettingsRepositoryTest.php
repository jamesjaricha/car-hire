<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\SettingsRepositoryContract;
use App\Enums\SettingKey;
use App\Models\Setting;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SettingsRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private SettingsRepositoryContract $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings = app(SettingsRepositoryContract::class);
    }

    public function test_it_casts_integers(): void
    {
        Setting::factory()->create(['key' => 'some_count', 'value' => '42', 'type' => 'integer']);

        $this->assertSame(42, $this->settings->integer('some_count'));
    }

    public function test_it_casts_booleans(): void
    {
        Setting::factory()->create(['key' => 'a_flag', 'value' => '1', 'type' => 'boolean']);
        Setting::factory()->create(['key' => 'another_flag', 'value' => '0', 'type' => 'boolean']);

        $this->assertTrue($this->settings->boolean('a_flag'));
        $this->assertFalse($this->settings->boolean('another_flag'));
    }

    public function test_decimals_are_normalised_to_the_configured_scale(): void
    {
        // Values arrive from forms and SQL unscaled. If they are not normalised
        // on the way out, bcmath arithmetic is numerically correct but exact
        // string assertions fail — which is how this bites, late and confusingly.
        Setting::factory()->create(['key' => 'a_fee', 'value' => '300', 'type' => 'decimal']);

        $this->assertSame('300.00', $this->settings->decimal('a_fee'));
    }

    public function test_it_returns_the_default_when_a_key_is_missing(): void
    {
        $this->assertSame(7, $this->settings->integer('not_a_real_key', 7));
        $this->assertSame('1.50', $this->settings->decimal('not_a_real_key', '1.5'));
        $this->assertTrue($this->settings->boolean('not_a_real_key', true));
    }

    public function test_writing_a_setting_invalidates_the_cache(): void
    {
        Setting::factory()->create(['key' => 'changes_often', 'value' => '1', 'type' => 'integer']);

        $this->assertSame(1, $this->settings->integer('changes_often'));

        $this->settings->set('changes_often', 2);

        // Would still read 1 if the cached snapshot were not flushed on write.
        $this->assertSame(2, $this->settings->integer('changes_often'));
    }

    public function test_the_seeder_records_locked_specification_values(): void
    {
        $this->seed(SettingsSeeder::class);

        $this->assertSame(50, $this->settings->integer(SettingKey::BookingDepositPercentage));
        $this->assertSame(4, $this->settings->integer(SettingKey::ShortNoticeThresholdHours));
        $this->assertSame(2, $this->settings->integer(SettingKey::DeadlinePickupMarginHours));
        $this->assertSame(30, $this->settings->integer(SettingKey::BasketTtlMinutes));
        $this->assertSame(120, $this->settings->integer(SettingKey::DefaultTurnaroundBufferMinutes));
    }

    public function test_outstanding_open_items_are_flagged_as_placeholders(): void
    {
        $this->seed(SettingsSeeder::class);

        $flagged = $this->settings->placeholders()->pluck('key');

        // These are spec §15 items nobody has answered yet. They must stay
        // visible rather than passing silently as though they were decided.
        $this->assertContains(SettingKey::AdminFeeAmount->value, $flagged);
        $this->assertContains(SettingKey::MinimumDriverAge->value, $flagged);
        $this->assertContains(SettingKey::SmsProvider->value, $flagged);

        // Values the specification does settle must NOT be flagged.
        $this->assertNotContains(SettingKey::BookingDepositPercentage->value, $flagged);
    }

    public function test_reseeding_does_not_overwrite_a_real_decision(): void
    {
        $this->seed(SettingsSeeder::class);

        $this->settings->set(SettingKey::AdminFeeAmount, '250.00', isPlaceholder: false);

        $this->seed(SettingsSeeder::class);

        $this->assertSame('250.00', $this->settings->decimal(SettingKey::AdminFeeAmount));
        $this->assertNotContains(
            SettingKey::AdminFeeAmount->value,
            $this->settings->placeholders()->pluck('key'),
        );
    }
}
