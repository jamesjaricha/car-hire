<?php

declare(strict_types=1);

namespace App\Services\Customers;

use App\Contracts\CustomerResolverContract;
use App\Contracts\PhoneNormaliserContract;
use App\DataTransferObjects\CustomerDetails;
use App\DataTransferObjects\CustomerResolutionResult;
use App\DataTransferObjects\NormalisedPhone;
use App\Enums\CustomerResolutionOutcome;
use App\Models\Customer;

/**
 * Spec §1.4, implemented.
 *
 * The instinct when a checkout email matches an existing customer is to attach
 * the booking to that customer. That instinct is wrong, and the specification
 * says so explicitly: anyone who knows your email address could otherwise
 * attach their booking to your record, and see your history.
 *
 * So a match never links. It creates a fresh record, notes the likely
 * duplicate, and leaves merging to a human. Linking happens only when identity
 * has actually been proven — a sign-in, or an OTP — which is signalled by the
 * caller passing a verified customer.
 */
final class CustomerResolver implements CustomerResolverContract
{
    public function __construct(
        private readonly PhoneNormaliserContract $phones,
    ) {}

    public function resolveForCheckout(
        CustomerDetails $details,
        ?Customer $verifiedCustomer = null,
    ): CustomerResolutionResult {
        // Normalise BEFORE matching. Matching on raw input against a normalised
        // column silently finds nothing and creates a duplicate every time.
        $phone = $this->phones->normalise($details->phone);

        $byEmail = $this->findByEmail($details->normalisedEmail());
        $byPhone = $phone->isMatchable() ? $this->findByPhone($phone->e164) : null;

        $matched = $byEmail instanceof Customer || $byPhone instanceof Customer;

        // Identity already proven this session. The only route to linking.
        if ($verifiedCustomer instanceof Customer) {
            // Deliberately does not overwrite the stored name, email or phone
            // from this form. The record has been verified; an unverified
            // checkout form is not grounds to change it.
            return new CustomerResolutionResult(
                customer: $verifiedCustomer,
                outcome: CustomerResolutionOutcome::LinkedExisting,
                anExistingRecordMatched: $matched,
            );
        }

        // Conflict rule: email points at one person, phone at another. We
        // cannot know which is right, so we choose neither and ask a human.
        if ($byEmail instanceof Customer && $byPhone instanceof Customer && ! $byEmail->is($byPhone)) {
            return new CustomerResolutionResult(
                customer: $this->createUnlinked($details, $phone, [
                    'needs_staff_review' => true,
                    'review_reason' => sprintf(
                        'Email matched customer #%d while phone matched customer #%d. '
                        .'Linked to neither, per specification §1.4.',
                        $byEmail->getKey(),
                        $byPhone->getKey(),
                    ),
                ]),
                outcome: CustomerResolutionOutcome::CreatedUnlinkedWithConflict,
                anExistingRecordMatched: true,
                hasConflict: true,
            );
        }

        if ($matched) {
            $existing = $byEmail ?? $byPhone;

            return new CustomerResolutionResult(
                customer: $this->createUnlinked($details, $phone, [
                    'possible_duplicate_of_customer_id' => $existing->getKey(),
                ]),
                outcome: CustomerResolutionOutcome::CreatedUnlinkedAfterMatch,
                anExistingRecordMatched: true,
            );
        }

        return new CustomerResolutionResult(
            customer: $this->createUnlinked($details, $phone),
            outcome: CustomerResolutionOutcome::Created,
            anExistingRecordMatched: false,
        );
    }

    /**
     * The earliest matching record, so a chain of duplicates all point back to
     * the original rather than to each other.
     */
    private function findByEmail(string $email): ?Customer
    {
        return Customer::query()
            ->where('email', $email)
            ->orderBy('id')
            ->first();
    }

    private function findByPhone(string $e164): ?Customer
    {
        return Customer::query()
            ->where('phone_e164', $e164)
            ->orderBy('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function createUnlinked(
        CustomerDetails $details,
        NormalisedPhone $phone,
        array $extra = [],
    ): Customer {
        return Customer::query()->create([
            'full_name' => $details->trimmedName(),
            'email' => $details->normalisedEmail(),
            // Null when unparseable, never a lookalike value.
            'phone_e164' => $phone->isMatchable() ? $phone->e164 : null,
            'phone_raw' => $phone->raw,
            'phone_region' => $phone->region,

            // Set explicitly even though the database defaults them. A model
            // returned by create() holds only the attributes that were passed
            // in — it does not know what default the database applied — so
            // reading these off the returned instance would otherwise give null
            // rather than false. Null is falsy, so the mistake survives an `if`
            // and shows up somewhere far away.
            'needs_staff_review' => false,
            'review_reason' => null,
            'possible_duplicate_of_customer_id' => null,

            ...$extra,
        ]);
    }
}
