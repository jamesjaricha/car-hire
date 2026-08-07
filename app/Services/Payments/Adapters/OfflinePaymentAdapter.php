<?php

declare(strict_types=1);

namespace App\Services\Payments\Adapters;

use App\Contracts\PaymentAdapterContract;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * What all four offline providers have in common.
 *
 * They differ in exactly two ways: what the operator must configure before the
 * method can be used, and the wording of the instructions. The second lives in
 * the database as `instructions_template`, because it is operator copy rather
 * than code — this class only fills in the merge fields.
 *
 * All of them require manual confirmation. That is not an accident of the
 * current implementations: mobile money at MVP is explicitly not a gateway
 * integration (spec §3.1), so a person checks a statement for every one of
 * them.
 */
abstract class OfflinePaymentAdapter implements PaymentAdapterContract
{
    /**
     * True for every offline method, by definition. Spec §3.1.
     */
    final public function requiresManualConfirmation(): bool
    {
        return true;
    }

    /**
     * @return list<string>
     */
    public function requiredAccountDetails(): array
    {
        return [];
    }

    /**
     * @return list<string>
     */
    final public function missingAccountDetails(PaymentMethod $method): array
    {
        $details = $method->account_details ?? [];

        return array_values(array_filter(
            $this->requiredAccountDetails(),
            static fn (string $key): bool => ! isset($details[$key])
                || trim((string) $details[$key]) === '',
        ));
    }

    final public function isConfigured(PaymentMethod $method): bool
    {
        return $this->missingAccountDetails($method) === [];
    }

    /**
     * Fill in the merge fields of spec §4.
     *
     * A field this adapter knows about but has no value for becomes an empty
     * string, rather than being left as `:account_number` on a customer's
     * screen. The gap is reported through missingAccountDetails() instead,
     * which is a question for the admin panel and not something to interrupt a
     * checkout over — an operator who has not entered their bank details yet
     * has a configuration problem, and refusing the booking would turn it into
     * a lost sale as well.
     *
     * A placeholder this adapter does NOT know about is left exactly as
     * written. Stripping anything that looks like `:word` would mean guessing
     * at operator copy, and getting that wrong deletes text from a customer's
     * instructions with no way to tell it happened.
     */
    public function instructionsFor(
        Payment $payment,
        PaymentMethod $method,
        ?CarbonImmutable $deadlineAt = null,
    ): string {
        $template = $method->instructions_template;

        if ($template === null || trim($template) === '') {
            return '';
        }

        return strtr($template, $this->mergeFields($payment, $method, $deadlineAt));
    }

    /**
     * @return array<string, string>
     */
    protected function mergeFields(
        Payment $payment,
        PaymentMethod $method,
        ?CarbonImmutable $deadlineAt,
    ): array {
        $fields = [
            ':reference' => $payment->payment_reference,
            ':amount' => $this->formatMoney($payment->expected_amount ?? $payment->amount, $payment->currency),
            ':method' => $method->label,
            ':deadline' => $deadlineAt === null ? '' : $this->formatDeadline($deadlineAt),
        ];

        // Every detail this method requires resolves to something, even when
        // the operator has not supplied it. Seeded first so that a real value
        // below overwrites the blank.
        foreach ($this->requiredAccountDetails() as $key) {
            $fields[':'.$key] = '';
        }

        foreach (($method->account_details ?? []) as $key => $value) {
            $fields[':'.$key] = (string) $value;
        }

        return $fields;
    }

    /**
     * Money as text, without ever becoming a float.
     *
     * No thousands separators, because `number_format()` takes a float and the
     * one rule this codebase does not bend is that money never becomes one —
     * not even on its way to being displayed. "ZMW 1155.00" is unambiguous, and
     * a customer copying a figure into a banking app does not want commas in it
     * anyway.
     */
    protected function formatMoney(string|int|null $amount, ?string $currency): string
    {
        return trim(($currency ?? '').' '.Money::of($amount));
    }

    /**
     * The deadline in the timezone the customer lives in.
     *
     * Instants are stored in UTC and converted only at the edge. This is the
     * edge — an instruction telling a Lusaka customer to pay by 12:30 when they
     * mean 14:30 is worse than giving them no deadline at all.
     */
    protected function formatDeadline(CarbonImmutable $deadlineAt): string
    {
        return $deadlineAt
            ->setTimezone((string) config('carhire.display_timezone', 'Africa/Lusaka'))
            ->format('j F Y \a\t H:i');
    }
}
