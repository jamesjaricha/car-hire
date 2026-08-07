<?php

declare(strict_types=1);

namespace App\Services\Payments\Adapters;

use App\Enums\PaymentMethodCode;

/**
 * A transfer into the operator's bank account, verified against a statement.
 *
 * Needs real account details before it can be offered honestly — instructions
 * to transfer money without an account number are instructions to send it
 * nowhere. The operator enters these in the admin panel; they are deliberately
 * not in source control.
 */
final class BankTransferAdapter extends OfflinePaymentAdapter
{
    public function code(): PaymentMethodCode
    {
        return PaymentMethodCode::BankTransfer;
    }

    /**
     * @return list<string>
     */
    public function requiredAccountDetails(): array
    {
        return ['bank_name', 'account_name', 'account_number'];
    }
}
