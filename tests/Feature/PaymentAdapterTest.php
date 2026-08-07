<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\PaymentAdapterResolverContract;
use App\Enums\PaymentMethodCode;
use App\Exceptions\PaymentMethodNotAvailableException;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Services\Payments\Adapters\AirtelMoneyAdapter;
use App\Services\Payments\Adapters\BankTransferAdapter;
use App\Services\Payments\Adapters\CashAdapter;
use App\Services\Payments\Adapters\MtnMomoAdapter;
use Carbon\CarbonImmutable;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PaymentAdapterTest extends TestCase
{
    use RefreshDatabase;

    private PaymentAdapterResolverContract $adapters;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PaymentMethodSeeder::class);

        $this->adapters = app(PaymentAdapterResolverContract::class);
    }

    public function test_each_offline_method_resolves_to_its_own_adapter(): void
    {
        $this->assertInstanceOf(CashAdapter::class, $this->adapters->for(PaymentMethodCode::Cash));
        $this->assertInstanceOf(BankTransferAdapter::class, $this->adapters->for(PaymentMethodCode::BankTransfer));
        $this->assertInstanceOf(MtnMomoAdapter::class, $this->adapters->for(PaymentMethodCode::MtnMomo));
        $this->assertInstanceOf(AirtelMoneyAdapter::class, $this->adapters->for(PaymentMethodCode::AirtelMoney));
    }

    /**
     * Spec §3.2 and the guideline: card methods are visible but not usable, and
     * no stub beyond the interface should exist. A stub would resolve cleanly
     * and do nothing, which is how a card payment comes to look as though it
     * had been taken.
     */
    public function test_card_methods_have_no_adapter(): void
    {
        $this->assertFalse($this->adapters->has(PaymentMethodCode::DebitCard));
        $this->assertFalse($this->adapters->has(PaymentMethodCode::CreditCard));

        $this->expectException(PaymentMethodNotAvailableException::class);

        $this->adapters->for(PaymentMethodCode::CreditCard);
    }

    /**
     * Spec §3.1: mobile money at MVP is manual verification against a
     * statement, not a gateway integration.
     */
    public function test_every_offline_method_requires_a_person_to_confirm_it(): void
    {
        foreach ([PaymentMethodCode::Cash, PaymentMethodCode::BankTransfer, PaymentMethodCode::MtnMomo, PaymentMethodCode::AirtelMoney] as $code) {
            $this->assertTrue(
                $this->adapters->for($code)->requiresManualConfirmation(),
                "[{$code->value}] should require manual confirmation.",
            );
        }
    }

    public function test_cash_needs_no_configuration(): void
    {
        // The customer walks in and hands money to a person. There is nothing
        // to set up, which is also why it is the only method that survives the
        // short-notice rule.
        $adapter = $this->adapters->for(PaymentMethodCode::Cash);
        $method = $this->method(PaymentMethodCode::Cash);

        $this->assertSame([], $adapter->requiredAccountDetails());
        $this->assertTrue($adapter->isConfigured($method));
    }

    public function test_a_bank_transfer_is_not_configured_until_the_account_is_entered(): void
    {
        $adapter = $this->adapters->for(PaymentMethodCode::BankTransfer);
        $method = $this->method(PaymentMethodCode::BankTransfer);

        // The seeder leaves account_details null on purpose — real account
        // numbers are operator data and are not in source control.
        $this->assertFalse($adapter->isConfigured($method));
        $this->assertEqualsCanonicalizing(
            ['bank_name', 'account_name', 'account_number'],
            $adapter->missingAccountDetails($method),
        );
    }

    public function test_a_blank_account_detail_counts_as_missing(): void
    {
        $adapter = $this->adapters->for(PaymentMethodCode::MtnMomo);

        $method = $this->method(PaymentMethodCode::MtnMomo);
        $method->forceFill(['account_details' => ['merchant_number' => '   ']])->save();

        $this->assertSame(['merchant_number'], $adapter->missingAccountDetails($method->refresh()));
    }

    public function test_a_configured_method_reports_nothing_missing(): void
    {
        $adapter = $this->adapters->for(PaymentMethodCode::AirtelMoney);

        $method = $this->method(PaymentMethodCode::AirtelMoney);
        $method->forceFill(['account_details' => ['merchant_number' => '556677']])->save();

        $this->assertTrue($adapter->isConfigured($method->refresh()));
        $this->assertSame([], $adapter->missingAccountDetails($method));
    }

    public function test_instructions_fill_in_the_reference_and_the_amount(): void
    {
        $adapter = $this->adapters->for(PaymentMethodCode::BankTransfer);

        $method = $this->method(PaymentMethodCode::BankTransfer);
        $method->forceFill([
            'instructions_template' => 'Send :amount quoting :reference to :account_number at :bank_name.',
            'account_details' => ['bank_name' => 'Zanaco', 'account_number' => '0123456789'],
        ])->save();

        $payment = Payment::factory()->create([
            'payment_reference' => 'BR-00042-1',
            'expected_amount' => '1155.00',
            'currency' => 'ZMW',
        ]);

        $this->assertSame(
            'Send ZMW 1155.00 quoting BR-00042-1 to 0123456789 at Zanaco.',
            $adapter->instructionsFor($payment, $method->refresh()),
        );
    }

    /**
     * Never with a float, not even for display. `number_format()` takes one,
     * which is why there are no thousands separators here — and a customer
     * copying a figure into a banking app does not want commas anyway.
     */
    public function test_the_amount_is_rendered_without_ever_becoming_a_float(): void
    {
        $adapter = $this->adapters->for(PaymentMethodCode::Cash);

        $method = $this->method(PaymentMethodCode::Cash);
        $method->forceFill(['instructions_template' => ':amount'])->save();

        $payment = Payment::factory()->create([
            'expected_amount' => '1234567.89',
            'currency' => 'ZMW',
        ]);

        $this->assertSame('ZMW 1234567.89', $adapter->instructionsFor($payment, $method->refresh()));
    }

    /**
     * Deadlines are stored in UTC and converted at the edge. This is the edge:
     * telling a Lusaka customer to pay by 12:30 when the deadline is 14:30
     * their time is worse than giving them no deadline at all.
     */
    public function test_the_deadline_is_rendered_in_the_display_timezone(): void
    {
        $adapter = $this->adapters->for(PaymentMethodCode::BankTransfer);

        $method = $this->method(PaymentMethodCode::BankTransfer);
        $method->forceFill(['instructions_template' => 'Pay by :deadline.'])->save();

        $payment = Payment::factory()->create();

        $this->assertSame(
            'Pay by 12 August 2026 at 14:30.',
            $adapter->instructionsFor(
                $payment,
                $method->refresh(),
                CarbonImmutable::parse('2026-08-12T12:30:00Z'),
            ),
        );
    }

    public function test_a_required_detail_with_no_value_renders_empty_rather_than_as_a_placeholder(): void
    {
        // ":account_number" on a customer's screen is worse than a gap. The
        // omission is reported through missingAccountDetails() instead, which
        // is a question for the admin panel rather than a reason to refuse a
        // booking and lose the sale.
        $adapter = $this->adapters->for(PaymentMethodCode::BankTransfer);

        $method = $this->method(PaymentMethodCode::BankTransfer);
        $method->forceFill([
            'instructions_template' => 'Account: :account_number.',
            'account_details' => null,
        ])->save();

        $this->assertSame(
            'Account: .',
            $adapter->instructionsFor(Payment::factory()->create(), $method->refresh()),
        );
    }

    /**
     * The other side of that rule, and the reason it is limited to details the
     * adapter actually declares: stripping anything shaped like `:word` would
     * mean guessing at operator copy, and guessing wrong deletes text from a
     * customer's instructions with nothing to show it happened.
     */
    public function test_a_placeholder_the_adapter_knows_nothing_about_is_left_alone(): void
    {
        $adapter = $this->adapters->for(PaymentMethodCode::BankTransfer);

        $method = $this->method(PaymentMethodCode::BankTransfer);
        $method->forceFill([
            'instructions_template' => 'Quote :reference at :swift_code.',
            'account_details' => null,
        ])->save();

        $payment = Payment::factory()->create(['payment_reference' => 'BR-00042-1']);

        $this->assertSame(
            'Quote BR-00042-1 at :swift_code.',
            $adapter->instructionsFor($payment, $method->refresh()),
        );
    }

    public function test_a_method_with_no_template_yields_no_instructions(): void
    {
        $adapter = $this->adapters->for(PaymentMethodCode::Cash);

        $method = $this->method(PaymentMethodCode::Cash);
        $method->forceFill(['instructions_template' => null])->save();

        $this->assertSame('', $adapter->instructionsFor(Payment::factory()->create(), $method->refresh()));
    }

    /**
     * Both mobile money providers behave identically at MVP but are separate
     * classes, because they are separate businesses with separate merchant
     * numbers and separate statements. The first to gain an API will need its
     * own adapter regardless.
     */
    public function test_the_two_mobile_money_providers_are_distinct_adapters(): void
    {
        $mtn = $this->adapters->for(PaymentMethodCode::MtnMomo);
        $airtel = $this->adapters->for(PaymentMethodCode::AirtelMoney);

        $this->assertNotSame($mtn, $airtel);
        $this->assertSame(PaymentMethodCode::MtnMomo, $mtn->code());
        $this->assertSame(PaymentMethodCode::AirtelMoney, $airtel->code());
    }

    private function method(PaymentMethodCode $code): PaymentMethod
    {
        return PaymentMethod::query()->where('code', $code->value)->sole();
    }
}
