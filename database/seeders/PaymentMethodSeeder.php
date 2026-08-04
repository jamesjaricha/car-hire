<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\PaymentMethodCode;
use App\Models\PaymentMethod;
use Illuminate\Database\Seeder;

/**
 * The six payment methods from spec §3.
 *
 * Four are enabled and all four are manually verified: cash, bank transfer,
 * MTN Mobile Money and Airtel Money. Mobile money is deliberately NOT a gateway
 * integration — staff confirm it against a statement exactly as they do a bank
 * transfer. No API, no PCI scope, no gateway credentials.
 *
 * The two card methods are seeded but disabled, so the checkout can show them
 * greyed out as "Coming Soon" without the front end inventing rows that do not
 * exist. Any request naming one is refused server-side.
 *
 * Uses firstOrCreate so re-seeding never overwrites account details or hold
 * durations the operator has since adjusted.
 */
final class PaymentMethodSeeder extends Seeder
{
    public function run(): void
    {
        foreach (PaymentMethodCode::cases() as $index => $code) {
            PaymentMethod::query()->firstOrCreate(
                ['code' => $code->value],
                [
                    'label' => $code->label(),
                    'type' => $code->type(),
                    // Card gateways are out of scope at MVP.
                    'enabled' => $code->type()->requiresManualConfirmation(),
                    'display_order' => $index,
                    'requires_manual_confirmation' => $code->type()->requiresManualConfirmation(),
                    'instructions_template' => $this->instructionsFor($code),
                    'account_details' => null,
                    'feature_flag' => $code->featureFlagName(),
                    'min_lead_time_hours' => null,
                    'hold_duration_hours' => $code->defaultHoldDurationHours(),
                ],
            );
        }
    }

    /**
     * Placeholder instructions. The real account and till numbers are operator
     * data entered through the admin panel — they are deliberately not in
     * source control.
     */
    private function instructionsFor(PaymentMethodCode $code): ?string
    {
        return match ($code) {
            PaymentMethodCode::Cash => 'Pay at the branch before your pickup time. Quote your payment reference :reference.',
            PaymentMethodCode::BankTransfer => 'Transfer :amount to the account shown, using :reference as the payment reference. '
                .'Your booking is confirmed once we have verified the funds.',
            PaymentMethodCode::MtnMomo, PaymentMethodCode::AirtelMoney => 'Send :amount to our merchant number and enter :reference as the reference. '
                .'Your booking is confirmed once a member of staff has verified the payment.',
            PaymentMethodCode::DebitCard, PaymentMethodCode::CreditCard => null,
        };
    }
}
