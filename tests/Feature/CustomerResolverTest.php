<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\CustomerResolverContract;
use App\DataTransferObjects\CustomerDetails;
use App\Enums\CustomerResolutionOutcome;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CustomerResolverTest extends TestCase
{
    use RefreshDatabase;

    private CustomerResolverContract $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = app(CustomerResolverContract::class);
    }

    public function test_a_new_customer_is_created_when_nothing_matches(): void
    {
        $result = $this->resolver->resolveForCheckout($this->checkoutDetails());

        $this->assertSame(CustomerResolutionOutcome::Created, $result->outcome);
        $this->assertFalse($result->anExistingRecordMatched);
        $this->assertFalse($result->hasConflict);
        $this->assertDatabaseCount('customers', 1);
    }

    public function test_details_are_stored_normalised(): void
    {
        $result = $this->resolver->resolveForCheckout(new CustomerDetails(
            fullName: '  Chanda Mwale  ',
            email: '  Chanda.Mwale@Example.COM ',
            phone: '0977123456',
        ));

        $customer = $result->customer;

        $this->assertSame('Chanda Mwale', $customer->full_name);
        $this->assertSame('chanda.mwale@example.com', $customer->email);
        $this->assertSame('+260977123456', $customer->phone_e164);
        $this->assertSame('0977123456', $customer->phone_raw);
        $this->assertSame('ZM', $customer->phone_region);
    }

    public function test_a_matching_email_does_not_link_to_the_existing_record(): void
    {
        // The heart of spec §1.4. Anyone who knows your email address could
        // otherwise attach their booking to your record.
        $existing = Customer::factory()->create([
            'email' => 'repeat@example.com',
            'phone_e164' => '+260955000111',
        ]);

        $result = $this->resolver->resolveForCheckout(new CustomerDetails(
            fullName: 'Someone Else',
            email: 'repeat@example.com',
            phone: '0966222333',
        ));

        $this->assertSame(CustomerResolutionOutcome::CreatedUnlinkedAfterMatch, $result->outcome);
        $this->assertTrue($result->customer->isNot($existing));
        $this->assertSame($existing->getKey(), $result->customer->possible_duplicate_of_customer_id);
        $this->assertDatabaseCount('customers', 2);
    }

    public function test_a_matching_phone_does_not_link_either(): void
    {
        $existing = Customer::factory()->create([
            'email' => 'original@example.com',
            'phone_e164' => '+260977123456',
        ]);

        $result = $this->resolver->resolveForCheckout(new CustomerDetails(
            fullName: 'Someone Else',
            email: 'different@example.com',
            phone: '0977123456',
        ));

        $this->assertSame(CustomerResolutionOutcome::CreatedUnlinkedAfterMatch, $result->outcome);
        $this->assertTrue($result->customer->isNot($existing));
        $this->assertSame($existing->getKey(), $result->customer->possible_duplicate_of_customer_id);
    }

    public function test_a_phone_typed_in_any_accepted_form_still_finds_the_match(): void
    {
        // The failure the guideline warns about: normalise on write, query with
        // raw input, and matching silently never happens.
        $existing = Customer::factory()->create(['phone_e164' => '+260977123456']);

        foreach (['0977123456', '260977123456', '+260 977 123 456'] as $typed) {
            $result = $this->resolver->resolveForCheckout(new CustomerDetails(
                fullName: 'Guest',
                email: 'guest'.md5($typed).'@example.com',
                phone: $typed,
            ));

            $this->assertSame(
                $existing->getKey(),
                $result->customer->possible_duplicate_of_customer_id,
                "A phone typed as [{$typed}] failed to find the existing record."
            );
        }
    }

    public function test_conflicting_matches_link_to_neither_and_are_flagged(): void
    {
        // Spec §1.4 conflict rule.
        $emailOwner = Customer::factory()->create([
            'email' => 'alice@example.com',
            'phone_e164' => '+260955000111',
        ]);

        $phoneOwner = Customer::factory()->create([
            'email' => 'bob@example.com',
            'phone_e164' => '+260977123456',
        ]);

        $result = $this->resolver->resolveForCheckout(new CustomerDetails(
            fullName: 'Confused Booking',
            email: 'alice@example.com',
            phone: '0977123456',
        ));

        $this->assertSame(CustomerResolutionOutcome::CreatedUnlinkedWithConflict, $result->outcome);
        $this->assertTrue($result->hasConflict);
        $this->assertTrue($result->requiresStaffAttention());

        $customer = $result->customer;
        $this->assertTrue($customer->isNot($emailOwner));
        $this->assertTrue($customer->isNot($phoneOwner));
        $this->assertTrue($customer->needs_staff_review);
        $this->assertStringContainsString((string) $emailOwner->getKey(), (string) $customer->review_reason);
        $this->assertStringContainsString((string) $phoneOwner->getKey(), (string) $customer->review_reason);

        // Neither existing record is touched.
        $this->assertFalse($emailOwner->fresh()->needs_staff_review);
        $this->assertFalse($phoneOwner->fresh()->needs_staff_review);
    }

    public function test_an_ordinary_duplicate_does_not_enter_the_review_queue(): void
    {
        // Every returning guest who does not sign in creates a duplicate. If
        // those filled the review queue, staff would stop reading it and the
        // genuine conflicts would be lost in the noise.
        Customer::factory()->create(['email' => 'repeat@example.com']);

        $result = $this->resolver->resolveForCheckout(new CustomerDetails(
            fullName: 'Repeat Guest',
            email: 'repeat@example.com',
            phone: '0966222333',
        ));

        $this->assertFalse($result->customer->needs_staff_review);
        $this->assertNotNull($result->customer->possible_duplicate_of_customer_id);
        $this->assertSame(0, Customer::query()->needingReview()->count());
    }

    public function test_linking_happens_only_when_identity_has_been_proven(): void
    {
        $existing = Customer::factory()->withAccount()->create([
            'email' => 'signed.in@example.com',
        ]);

        $result = $this->resolver->resolveForCheckout(
            new CustomerDetails(
                fullName: 'Signed In',
                email: 'signed.in@example.com',
                phone: '0977123456',
            ),
            verifiedCustomer: $existing,
        );

        $this->assertSame(CustomerResolutionOutcome::LinkedExisting, $result->outcome);
        $this->assertTrue($result->customer->is($existing));
        $this->assertDatabaseCount('customers', 1);
    }

    public function test_a_verified_customers_stored_details_are_not_overwritten_by_the_form(): void
    {
        $existing = Customer::factory()->withAccount()->create([
            'full_name' => 'Chanda Mwale',
            'email' => 'chanda@example.com',
        ]);

        $this->resolver->resolveForCheckout(
            new CustomerDetails(
                fullName: 'Totally Different Name',
                email: 'chanda@example.com',
                phone: '0977123456',
            ),
            verifiedCustomer: $existing,
        );

        $this->assertSame('Chanda Mwale', $existing->fresh()->full_name);
    }

    public function test_duplicates_all_point_back_to_the_original_record(): void
    {
        $original = Customer::factory()->create(['email' => 'serial@example.com']);

        $second = $this->resolver->resolveForCheckout(
            new CustomerDetails('Guest Two', 'serial@example.com', '0966222333')
        )->customer;

        $third = $this->resolver->resolveForCheckout(
            new CustomerDetails('Guest Three', 'serial@example.com', '0955444555')
        )->customer;

        // Not a chain pointing at each other — both point at the original, so
        // a staff merge has one obvious target.
        $this->assertSame($original->getKey(), $second->possible_duplicate_of_customer_id);
        $this->assertSame($original->getKey(), $third->possible_duplicate_of_customer_id);
    }

    public function test_an_unparseable_phone_is_never_stored_as_though_it_were_canonical(): void
    {
        // A junk value in phone_e164 would match every other junk value and
        // link unrelated people to one another.
        $result = $this->resolver->resolveForCheckout(new CustomerDetails(
            fullName: 'Bad Phone',
            email: 'bad.phone@example.com',
            phone: 'not a number',
        ));

        $this->assertNull($result->customer->phone_e164);
        $this->assertSame('not a number', $result->customer->phone_raw);
    }

    public function test_two_unparseable_phones_do_not_match_each_other(): void
    {
        $this->resolver->resolveForCheckout(
            new CustomerDetails('First', 'first@example.com', 'call the office')
        );

        $result = $this->resolver->resolveForCheckout(
            new CustomerDetails('Second', 'second@example.com', 'call the office')
        );

        $this->assertSame(CustomerResolutionOutcome::Created, $result->outcome);
        $this->assertNull($result->customer->possible_duplicate_of_customer_id);
    }

    private function checkoutDetails(): CustomerDetails
    {
        return new CustomerDetails(
            fullName: 'Chanda Mwale',
            email: 'chanda.mwale@example.com',
            phone: '0977123456',
        );
    }
}
