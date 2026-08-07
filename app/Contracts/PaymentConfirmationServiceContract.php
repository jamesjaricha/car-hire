<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DataTransferObjects\PaymentConfirmationResult;
use App\Exceptions\PaymentNotConfirmableException;
use App\Exceptions\StaffPermissionDeniedException;
use App\Models\Payment;
use App\Models\User;

/**
 * A staff member states that money actually arrived.
 *
 * The only thing in the platform that moves a booking forward on the strength
 * of payment. Spec §7.3: `proof_submitted` never confirms a booking on its own,
 * and neither does a customer telling us they have paid. Only this.
 *
 * The sole writer to `payment_confirmations`, for the same reason
 * `VehicleHoldService::place()` is the sole writer to `vehicle_holds`: the
 * guard that makes it safe lives here, and a second writer removes it silently.
 */
interface PaymentConfirmationServiceContract
{
    /**
     * Confirm that `$amountReceived` arrived against this payment.
     *
     * The amount is required rather than defaulted to what was expected. Spec
     * §5 and the guideline both anticipate customers sending the wrong figure,
     * and a confirm button that assumes the expected amount is a button that
     * records money nobody counted.
     *
     * @throws StaffPermissionDeniedException when §12 does not grant this
     *                                        person this method, or the §15.12
     *                                        cash setting withholds it.
     * @throws PaymentNotConfirmableException when the payment is unattributed,
     *                                        already confirmed, or in a state
     *                                        that forbids it.
     */
    public function confirm(
        User $actor,
        Payment $payment,
        string $amountReceived,
        ?string $notes = null,
    ): PaymentConfirmationResult;
}
