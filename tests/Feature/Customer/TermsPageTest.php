<?php

declare(strict_types=1);

namespace Tests\Feature\Customer;

use App\Contracts\SettingsRepositoryContract;
use App\Enums\SettingKey;
use App\Models\Branch;
use App\Models\VehicleClass;
use Database\Seeders\DemoPaymentDetailsSeeder;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The terms of hire, assembled from what the platform actually charges.
 *
 * Spec §6 requires the security deposit to appear in the terms and conditions
 * and §10 the insurance excess. There were no terms at all before this, so
 * those were plain spec gaps rather than matters of polish.
 *
 * THE ASSERTIONS THAT EARN THEIR PLACE ARE ABOUT WHAT MUST NOT APPEAR.
 *
 * A term is a contractual statement. Printing a §15 placeholder here does not
 * merely look untidy, as it would on an internal screen — it publishes a
 * promise the operator never made, to every customer who reads the page. "An
 * administration fee of ZMW 0.00 applies" is the exact failure
 * [[feedback_undecided_is_not_zero]] describes, at its highest stakes.
 *
 * And the mirror of it: a DECIDED zero must print normally, because that is
 * a real answer somebody chose. If the page could not tell those apart, the
 * nullable columns would have been pointless.
 */
final class TermsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SettingsSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
        $this->seed(DemoPaymentDetailsSeeder::class);
    }

    public function test_it_publishes_the_deposit_and_excess_for_every_sellable_class(): void
    {
        $this->branch();

        VehicleClass::factory()->create([
            'name' => 'Economy',
            'slug' => 'economy',
            'security_deposit_amount' => '2500.00',
            'insurance_excess_amount' => '5000.00',
        ]);

        $this->get(route('terms'))
            ->assertSuccessful()
            ->assertSee('Economy')
            // Spec §6 and §10, and the reason this page exists at all.
            ->assertSee('2,500.00')
            ->assertSee('5,000.00');
    }

    /**
     * An unpriced class is withheld from search, so publishing its terms would
     * describe a hire nobody can book — and its figures are the undecided ones.
     */
    public function test_a_class_awaiting_a_pricing_decision_is_not_published(): void
    {
        $this->branch();

        VehicleClass::factory()->create(['name' => 'Sellable', 'slug' => 'sellable']);
        VehicleClass::factory()->create([
            'name' => 'Unpriced',
            'slug' => 'unpriced',
            'insurance_excess_amount' => null,
        ]);

        $this->get(route('terms'))
            ->assertSuccessful()
            ->assertSee('Sellable')
            ->assertDontSee('Unpriced');
    }

    // --- The rule that matters ----------------------------------------------

    /**
     * THE ONE THAT MATTERS.
     *
     * `SettingsSeeder` seeds the admin fee as a PLACEHOLDER of 0.00. Printing
     * it would tell every customer, in the terms they are asked to accept, that
     * cancelling costs them nothing — a promise nobody made, and one the
     * operator would be held to.
     */
    public function test_an_undecided_figure_is_never_published_as_a_term(): void
    {
        $this->branch();
        $this->sellableClass();

        $this->assertTrue(
            app(SettingsRepositoryContract::class)->isPlaceholder(SettingKey::AdminFeeAmount),
            'This test is meaningless unless the admin fee starts as a placeholder.',
        );

        $response = $this->get(route('terms'))->assertSuccessful();

        // "not yet set", NOT "confirmed before you pay" — the badge is
        // substituted into eight different sentences and has to read correctly
        // in all of them. The earlier wording produced "The administration fee
        // deducted from a refund is confirmed before you pay", which states the
        // opposite of what it means.
        $response->assertSee('not yet set', escape: false);
        $response->assertDontSee('is confirmed before you pay', escape: false);

        // The figure itself must be absent. The class fixture uses non-zero
        // deposits so this cannot collide with a legitimate 0.00 elsewhere.
        $this->assertStringNotContainsString('ZMW 0.00', $response->getContent());
    }

    public function test_a_decided_figure_is_published(): void
    {
        $this->branch();
        $this->sellableClass();

        app(SettingsRepositoryContract::class)
            ->set(SettingKey::AdminFeeAmount, '150.00', isPlaceholder: false);

        $this->get(route('terms'))
            ->assertSuccessful()
            ->assertSee('150.00');
    }

    /**
     * THE MIRROR, and the reason the placeholder flag exists rather than a
     * "is it zero" check. An operator who genuinely charges nothing to cancel
     * has made a decision, and the page must state it.
     */
    public function test_a_decided_zero_is_published_as_a_real_answer(): void
    {
        $this->branch();
        $this->sellableClass();

        app(SettingsRepositoryContract::class)
            ->set(SettingKey::AdminFeeAmount, '0.00', isPlaceholder: false);

        $response = $this->get(route('terms'))->assertSuccessful();

        $this->assertStringContainsString('ZMW 0.00', $response->getContent());
    }

    // --- The rest of the page -----------------------------------------------

    public function test_it_names_the_payment_methods_a_customer_may_use(): void
    {
        $this->branch();
        $this->sellableClass();

        $this->get(route('terms'))
            ->assertSuccessful()
            // Cash requires no account details, so it is offerable on any
            // install and is the safe thing to assert.
            ->assertSee('Cash');
    }

    /**
     * Spec §7.3: proof of payment never confirms a booking on its own, and the
     * terms must not let a customer believe otherwise.
     */
    public function test_it_states_that_payment_is_verified_by_a_person(): void
    {
        $this->branch();
        $this->sellableClass();

        $this->get(route('terms'))
            ->assertSuccessful()
            ->assertSee('verified the payment', escape: false)
            ->assertSee('No payment is taken automatically', escape: false);
    }

    public function test_it_lists_the_branches_and_says_when_hours_are_unpublished(): void
    {
        $this->branch(['name' => 'Lusaka Branch', 'opens_at' => null, 'closes_at' => null]);
        $this->sellableClass();

        $this->get(route('terms'))
            ->assertSuccessful()
            ->assertSee('Lusaka Branch')
            ->assertSee('hours not published', escape: false);
    }

    public function test_the_page_is_reachable_from_every_page(): void
    {
        $this->branch();

        $this->get(route('home'))
            ->assertSuccessful()
            ->assertSee(route('terms'), escape: false);
    }

    // --- Fixtures -----------------------------------------------------------

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function branch(array $attributes = []): Branch
    {
        return Branch::factory()->create($attributes);
    }

    /**
     * Deliberately non-zero figures, so a legitimate deposit can never be
     * mistaken for the undecided admin fee this suite is watching for.
     */
    private function sellableClass(): VehicleClass
    {
        return VehicleClass::factory()->create([
            'name' => 'Economy',
            'slug' => 'economy',
            'security_deposit_amount' => '2500.00',
            'insurance_excess_amount' => '5000.00',
        ]);
    }
}
