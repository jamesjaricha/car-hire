<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\PaymentMethodServiceContract;
use App\Enums\PaymentMethodCode;
use App\Exceptions\PaymentMethodNotAvailableException;
use App\Models\PaymentMethod;
use Carbon\CarbonImmutable;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PaymentMethodServiceTest extends TestCase
{
    use RefreshDatabase;

    private PaymentMethodServiceContract $methods;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::parse('2026-09-06T21:00:00Z');
        $this->travelTo($this->now);

        $this->seed(PaymentMethodSeeder::class);

        $this->methods = app(PaymentMethodServiceContract::class);
    }

    public function test_all_four_offline_methods_are_selectable_for_a_normal_booking(): void
    {
        $selectable = $this->methods
            ->selectableFor($this->now->addDay())
            ->map(fn (PaymentMethod $m): string => $m->code->value)
            ->all();

        $this->assertEqualsCanonicalizing(
            ['cash', 'bank_transfer', 'mtn_momo', 'airtel_money'],
            $selectable,
        );
    }

    public function test_card_methods_are_visible_but_never_selectable(): void
    {
        // Spec §3.2: shown greyed out as "Coming Soon". They must appear in the
        // list the checkout renders, and must not appear in what may be chosen.
        $displayed = $this->methods->displayable()
            ->map(fn (PaymentMethod $m): string => $m->code->value)
            ->all();

        $this->assertContains('debit_card', $displayed);
        $this->assertContains('credit_card', $displayed);

        $selectable = $this->methods->selectableFor($this->now->addDay())
            ->map(fn (PaymentMethod $m): string => $m->code->value)
            ->all();

        $this->assertNotContains('debit_card', $selectable);
        $this->assertNotContains('credit_card', $selectable);
    }

    /**
     * Guideline §6: a disabled payment method submitted directly must be
     * refused. Greying out a button stops an honest customer and nothing else.
     */
    public function test_a_disabled_method_submitted_directly_is_refused(): void
    {
        $this->expectException(PaymentMethodNotAvailableException::class);
        $this->expectExceptionMessage('not currently accepted');

        $this->methods->assertSelectable('debit_card', $this->now->addDay());
    }

    public function test_an_invented_method_code_is_refused(): void
    {
        $this->expectException(PaymentMethodNotAvailableException::class);
        $this->expectExceptionMessage('no payment method with the code');

        $this->methods->assertSelectable('bitcoin', $this->now->addDay());
    }

    /**
     * Guideline §6: "Pickup in 3 hours → no online methods offered".
     */
    public function test_an_imminent_pickup_leaves_only_cash_at_the_branch(): void
    {
        $selectable = $this->methods->selectableFor($this->now->addHours(3))
            ->map(fn (PaymentMethod $m): string => $m->code->value)
            ->all();

        $this->assertSame(['cash'], $selectable);
    }

    public function test_a_remote_method_is_refused_when_pickup_is_imminent(): void
    {
        $this->expectException(PaymentMethodNotAvailableException::class);
        $this->expectExceptionMessage('less than 4 hours away');

        $this->methods->assertSelectable('bank_transfer', $this->now->addHours(3));
    }

    public function test_cash_is_still_accepted_when_pickup_is_imminent(): void
    {
        $method = $this->methods->assertSelectable('cash', $this->now->addHours(3));

        $this->assertSame(PaymentMethodCode::Cash, $method->code);
    }

    public function test_a_deployment_flag_overrides_the_operators_switch(): void
    {
        // The row says enabled; the deployment says no. The deployment wins —
        // this is how a compromised merchant number gets shut off without
        // database access.
        $this->assertTrue(PaymentMethod::query()->where('code', 'bank_transfer')->value('enabled'));

        config(['carhire.payment_methods.bank_transfer' => false]);

        $this->expectException(PaymentMethodNotAvailableException::class);

        $this->methods->assertSelectable('bank_transfer', $this->now->addDay());
    }

    public function test_the_operators_switch_alone_is_enough_to_disable_a_method(): void
    {
        PaymentMethod::query()->where('code', 'mtn_momo')->update(['enabled' => false]);

        $selectable = $this->methods->selectableFor($this->now->addDay())
            ->map(fn (PaymentMethod $m): string => $m->code->value)
            ->all();

        $this->assertNotContains('mtn_momo', $selectable);
    }

    public function test_a_per_method_lead_time_is_enforced(): void
    {
        PaymentMethod::query()
            ->where('code', 'bank_transfer')
            ->update(['min_lead_time_hours' => 48]);

        $this->expectException(PaymentMethodNotAvailableException::class);
        $this->expectExceptionMessage('requires at least 48 hours');

        $this->methods->assertSelectable('bank_transfer', $this->now->addDay());
    }

    public function test_a_method_within_its_lead_time_is_accepted(): void
    {
        PaymentMethod::query()
            ->where('code', 'bank_transfer')
            ->update(['min_lead_time_hours' => 12]);

        $method = $this->methods->assertSelectable('bank_transfer', $this->now->addDay());

        $this->assertSame(PaymentMethodCode::BankTransfer, $method->code);
    }

    public function test_the_seeder_records_the_specifications_hold_durations(): void
    {
        // Spec §8.1.
        $expected = [
            'cash' => 24,
            'bank_transfer' => 48,
            'mtn_momo' => 6,
            'airtel_money' => 6,
        ];

        foreach ($expected as $code => $hours) {
            $this->assertSame(
                $hours,
                PaymentMethod::query()->where('code', $code)->value('hold_duration_hours'),
                "Hold duration for [{$code}] does not match the specification."
            );
        }
    }

    public function test_reseeding_does_not_overwrite_operator_changes(): void
    {
        PaymentMethod::query()->where('code', 'cash')->update(['hold_duration_hours' => 12]);

        $this->seed(PaymentMethodSeeder::class);

        $this->assertSame(
            12,
            PaymentMethod::query()->where('code', 'cash')->value('hold_duration_hours'),
        );
    }
}
