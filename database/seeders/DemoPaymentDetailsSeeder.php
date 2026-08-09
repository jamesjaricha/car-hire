<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\PaymentMethodCode;
use App\Models\PaymentMethod;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Obviously fake account details, so that a local site and the test suite have
 * a working checkout.
 *
 * WHY THESE ARE NOT IN PaymentMethodSeeder
 *
 * That seeder runs in production. Since 2026-08-09 a method with no account
 * details is withheld from customers, which means seeding plausible-looking
 * details there would do real harm: bank transfer would be offered on a live
 * site, `isConfigured()` would report everything fine, and customers would be
 * told to send money to an account belonging to nobody. Better that a fresh
 * production install offers cash only until the operator enters real numbers.
 *
 * The values below are deliberately unusable — "Demo Bank", an account number
 * of all zeros — so that if they ever do reach a real customer, it is obvious
 * at a glance rather than after a failed transfer.
 *
 * REFUSES TO RUN IN PRODUCTION
 *
 * Not local-only, unlike `DemoStaffSeeder`: the test suite needs these, and
 * `testing` is not `local`. The guard is against the one environment where fake
 * payment details cause harm.
 */
final class DemoPaymentDetailsSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException(
                'DemoPaymentDetailsSeeder writes fake account details and must never run in production. '
                .'Enter real details through the admin panel instead.'
            );
        }

        foreach ($this->details() as $code => $details) {
            PaymentMethod::query()
                ->where('code', $code)
                // Never overwrite. Somebody who has entered real details on a
                // local machine to reproduce something should not lose them to
                // a re-seed.
                ->whereNull('account_details')
                ->update(['account_details' => json_encode($details, JSON_THROW_ON_ERROR)]);
        }
    }

    /**
     * Keyed by the same names the adapters declare in requiredAccountDetails(),
     * because those are what the instruction templates merge on. Cash appears
     * nowhere here: `CashAdapter` requires nothing, so it is always configured
     * and is what keeps checkout working on a fresh production install.
     *
     * `branch_code` is extra — not required by `BankTransferAdapter`, but a
     * realistic thing for an operator to want in their template, and it
     * exercises the case of a detail nobody validates.
     *
     * @return array<string, array<string, string>>
     */
    private function details(): array
    {
        return [
            PaymentMethodCode::BankTransfer->value => [
                'bank_name' => 'Demo Bank (not a real bank)',
                'account_name' => 'DEMO ONLY — Car Hire Ltd',
                'account_number' => '0000000000',
                'branch_code' => '000000',
            ],

            PaymentMethodCode::MtnMomo->value => [
                'merchant_name' => 'DEMO ONLY — Car Hire Ltd',
                'merchant_number' => '0000000000',
            ],

            PaymentMethodCode::AirtelMoney->value => [
                'merchant_name' => 'DEMO ONLY — Car Hire Ltd',
                'merchant_number' => '0000000000',
            ],
        ];
    }
}
