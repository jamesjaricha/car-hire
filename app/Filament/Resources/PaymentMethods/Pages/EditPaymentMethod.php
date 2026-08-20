<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentMethods\Pages;

use App\Filament\Resources\PaymentMethods\PaymentMethodResource;
use App\Filament\Resources\PaymentMethods\Schemas\PaymentMethodForm;
use App\Models\PaymentMethod;
use Filament\Resources\Pages\EditRecord;

/**
 * No delete action. A method is switched off with `enabled`; deleting the row
 * would leave historic payments pointing at a code nothing can describe.
 */
final class EditPaymentMethod extends EditRecord
{
    protected static string $resource = PaymentMethodResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * Put an empty row on the form for every account detail this method needs.
     *
     * WHY THE OPERATOR CANNOT BE ASKED TO TYPE THE KEYS
     *
     * They are exact snake_case identifiers an adapter looks up —
     * `bank_name`, `account_number`, `merchant_number`. Presented with a blank
     * key/value grid, the operator has to know our vocabulary and spell it
     * correctly, and any other spelling is refused with a message naming fields
     * they were never given a box for. Reported as an error that "looks like the
     * data type I had put was invalid", which is what guessing an internal
     * contract feels like from outside.
     *
     * ⚠ WHY HERE AND NOT IN AN `afterStateHydrated` ON THE FIELD
     *
     * That was the first attempt and it was worse than the problem. It shipped
     * to production on 2026-08-19, and the operator filled in all three bank
     * fields, watched them sit there on screen, and was still told they were
     * empty.
     *
     * `afterStateHydrated` runs on EVERY hydration, not once at fill — including
     * the one after a failed validation, and every Livewire round trip in
     * between. It read the component's state, rebuilt it, and wrote it back. The
     * browser kept displaying what had been typed while the server-side state it
     * rebuilt no longer matched, so the validator correctly reported empty
     * fields for values plainly visible on screen. **A validation error a user
     * cannot act on is worse than the missing feature it was added to fix.**
     *
     * `mutateFormDataBeforeFill()` runs ONCE, on fill, against the model's plain
     * attributes, before any component or Alpine state shaping exists to
     * disturb. Nothing it touches is re-entered on submit.
     *
     * ⚠ The suite did not catch this. `fillForm()` sets an associative array
     * straight into the form state, so it never exercises the path a browser
     * takes. A Filament form test proves the RULES, not the round trip.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();

        if (! $record instanceof PaymentMethod) {
            return $data;
        }

        $details = is_array($data['account_details'] ?? null) ? $data['account_details'] : [];

        $ordered = [];

        // Required first, in the adapter's own order, so the form reads top to
        // bottom the way somebody copying off a bank statement expects.
        foreach (PaymentMethodForm::requiredKeys($record) as $key) {
            $ordered[$key] = $details[$key] ?? '';
        }

        // Anything else the operator has added stays, and still works as a
        // :merge_field in the instructions.
        foreach ($details as $key => $value) {
            if (! array_key_exists($key, $ordered)) {
                $ordered[$key] = $value;
            }
        }

        $data['account_details'] = $ordered;

        return $data;
    }
}
