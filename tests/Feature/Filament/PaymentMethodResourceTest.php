<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\StaffRole;
use App\Filament\Resources\PaymentMethods\Pages\EditPaymentMethod;
use App\Filament\Resources\PaymentMethods\PaymentMethodResource;
use App\Models\PaymentMethod;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The screen that closes a live customer-facing gap: `account_details` has been
 * empty on every method since Phase 2, so bank transfer and mobile money
 * instructions rendered with blanks where the account and till numbers belong.
 *
 * Two rules carry the weight, and both are tested here: the details a method's
 * adapter requires must be present, and every `:placeholder` in the instructions
 * must be one something can fill.
 */
final class PaymentMethodResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        // Deliberately NOT DemoPaymentDetailsSeeder: this suite is about the
        // screen that fills those details in.
        $this->seed(PaymentMethodSeeder::class);
    }

    // --- Who may ------------------------------------------------------------

    public function test_a_super_admin_may_manage_payment_methods(): void
    {
        $this->actingAs($this->admin())
            ->get(PaymentMethodResource::getUrl('index'))
            ->assertSuccessful();
    }

    /**
     * Spec §12: the one row a Branch Manager does not hold.
     */
    public function test_a_branch_manager_may_not(): void
    {
        $this->actingAs(User::factory()->withRole(StaffRole::BranchManager)->create())
            ->get(PaymentMethodResource::getUrl('index'))
            ->assertForbidden();
    }

    // --- The six rows are the enum ------------------------------------------

    public function test_there_is_no_create_or_delete_route(): void
    {
        $this->assertSame(['index', 'edit'], array_keys(PaymentMethodResource::getPages()));
    }

    public function test_the_policy_refuses_creation_and_deletion(): void
    {
        $method = $this->bankTransfer();

        // A row whose code matches no enum case has no adapter and no way to
        // produce instructions; deleting one strands historic payments.
        $this->assertFalse($this->admin()->can('create', PaymentMethod::class));
        $this->assertFalse($this->admin()->can('delete', $method));
        $this->assertTrue($this->admin()->can('update', $method));
    }

    // --- Account details ----------------------------------------------------

    public function test_account_details_can_be_entered(): void
    {
        $method = $this->bankTransfer();

        Livewire::actingAs($this->admin())
            ->test(EditPaymentMethod::class, ['record' => $method->getKey()])
            ->fillForm([
                'account_details' => [
                    'bank_name' => 'Zanaco',
                    'account_name' => 'Car Hire Ltd',
                    'account_number' => '1234567890',
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('1234567890', $method->refresh()->account_details['account_number']);
    }

    /**
     * The operator must never have to GUESS a key name.
     *
     * REGRESSION. `bank_name`, `account_name` and `account_number` are exact
     * identifiers an adapter looks up. The form was an empty key/value grid, so
     * entering bank details meant typing those three keys from scratch, spelled
     * precisely — and any other spelling was refused with a message naming
     * fields the operator had never been shown a box for. Reported as an error
     * that "looks like the data type I had put was invalid", which is what
     * guessing an internal contract feels like from the outside.
     *
     * The rows are now seeded empty, so the job is filling blanks rather than
     * knowing our vocabulary.
     */
    public function test_the_required_fields_are_already_on_the_form_waiting_for_values(): void
    {
        $method = $this->bankTransfer();

        $this->assertNull($method->account_details);

        Livewire::actingAs($this->admin())
            ->test(EditPaymentMethod::class, ['record' => $method->getKey()])
            ->assertFormSet([
                'account_details' => [
                    'bank_name' => '',
                    'account_name' => '',
                    'account_number' => '',
                ],
            ]);
    }

    /**
     * Seeding the blanks must not disturb details already entered, nor discard
     * extra fields the operator added for use as :merge_fields.
     */
    public function test_seeding_the_blanks_preserves_existing_and_extra_details(): void
    {
        $method = $this->bankTransfer();

        $method->forceFill([
            'account_details' => [
                'account_number' => '1234567890',
                'swift_code' => 'ZANAZMLU',
            ],
        ])->save();

        Livewire::actingAs($this->admin())
            ->test(EditPaymentMethod::class, ['record' => $method->getKey()])
            ->assertFormSet([
                'account_details' => [
                    'bank_name' => '',
                    'account_name' => '',
                    'account_number' => '1234567890',
                    'swift_code' => 'ZANAZMLU',
                ],
            ]);
    }

    /**
     * ⚠ THE SHAPE A BROWSER ACTUALLY SUBMITS.
     *
     * REGRESSION, and the one that mattered most: this screen could not save
     * account details at all, in any browser, from the day it shipped
     * (2026-08-09) until 2026-08-20.
     *
     * Filament's `KeyValue` holds its state as a LIST of `{key, value}` rows and
     * folds it into an associative array only when it DEHYDRATES. Validation
     * runs before that, so the rules were looking up `$details['bank_name']` in
     * a list with numeric indices, finding nothing, and reporting every field
     * empty while the operator looked at the values they had typed.
     *
     * Every other test here passed throughout, because `fillForm()` sets an
     * associative array straight into form state — a shape a browser never
     * sends. **A Filament form test proves the rules; only feeding it the row
     * shape proves the round trip.**
     *
     * Confirmed by reading `Livewire.all()` state in the live page. Two earlier
     * explanations were plausible and wrong.
     */
    public function test_it_accepts_the_row_shape_a_browser_submits(): void
    {
        $method = $this->bankTransfer();

        Livewire::actingAs($this->admin())
            ->test(EditPaymentMethod::class, ['record' => $method->getKey()])
            ->fillForm([
                'enabled' => true,
                'account_details' => [
                    ['key' => 'bank_name', 'value' => 'Stanbic'],
                    ['key' => 'account_name', 'value' => 'Pule Car Hire'],
                    ['key' => 'account_number', 'value' => '12345678910'],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        // And it must land in the database associatively, because that is what
        // the adapter reads when it builds a customer's instructions.
        $this->assertSame(
            [
                'bank_name' => 'Stanbic',
                'account_name' => 'Pule Car Hire',
                'account_number' => '12345678910',
            ],
            $method->refresh()->account_details,
        );
    }

    /**
     * A row the operator added and never typed into is not a field, and must
     * not be mistaken for one — in either direction.
     */
    public function test_a_blank_row_is_ignored_rather_than_counted(): void
    {
        $method = $this->bankTransfer();

        Livewire::actingAs($this->admin())
            ->test(EditPaymentMethod::class, ['record' => $method->getKey()])
            ->fillForm([
                'enabled' => true,
                'account_details' => [
                    ['key' => 'bank_name', 'value' => 'Stanbic'],
                    ['key' => 'account_name', 'value' => 'Pule Car Hire'],
                    ['key' => 'account_number', 'value' => '12345678910'],
                    ['key' => '', 'value' => ''],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertArrayNotHasKey('', $method->refresh()->account_details);
    }

    /**
     * The rule that stops a method being switched ON but unusable. The checkout
     * gate refuses it anyway; this means the operator finds out on the screen
     * where they can fix it, rather than by noticing an option has vanished.
     */
    public function test_it_refuses_details_missing_while_the_method_is_switched_on(): void
    {
        $method = $this->bankTransfer();

        Livewire::actingAs($this->admin())
            ->test(EditPaymentMethod::class, ['record' => $method->getKey()])
            ->fillForm([
                'enabled' => true,
                'account_details' => ['bank_name' => 'Zanaco'],
            ])
            ->call('save')
            ->assertHasFormErrors(['account_details']);

        $this->assertNull($method->refresh()->account_details);
    }

    /**
     * THE WAY OUT, and it did not exist until 2026-08-19.
     *
     * The rule used to refuse any save with a required detail missing, while
     * the message told the operator that leaving it blank was safe. Both halves
     * were defensible and together they were a loop: no value would save, and
     * nothing on screen said switching the method off was the alternative.
     *
     * Blocking a save protects nobody when the method is off — `Payment
     * MethodService` withholds an unconfigured method from checkout regardless.
     * What it did was stop the operator recording partial work, which is the
     * ordinary case when the bank details are on somebody else's desk.
     */
    public function test_a_switched_off_method_may_be_saved_part_finished(): void
    {
        $method = $this->bankTransfer();

        Livewire::actingAs($this->admin())
            ->test(EditPaymentMethod::class, ['record' => $method->getKey()])
            ->fillForm([
                'enabled' => false,
                'account_details' => ['bank_name' => 'Zanaco'],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Zanaco', $method->refresh()->account_details['bank_name']);
    }

    public function test_cash_needs_no_account_details(): void
    {
        $cash = PaymentMethod::query()->where('code', 'cash')->firstOrFail();

        Livewire::actingAs($this->admin())
            ->test(EditPaymentMethod::class, ['record' => $cash->getKey()])
            ->fillForm(['hold_duration_hours' => 12])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(12, $cash->refresh()->hold_duration_hours);
    }

    // --- The instructions template ------------------------------------------

    /**
     * `OfflinePaymentAdapter` leaves an unknown `:placeholder` exactly as
     * written, because stripping anything that looks like one would mean
     * guessing at operator copy. So an unsupplied field is printed to the
     * customer verbatim, and this is where that gets caught.
     */
    public function test_it_refuses_a_template_using_a_placeholder_nothing_fills(): void
    {
        $method = $this->bankTransfer();

        Livewire::actingAs($this->admin())
            ->test(EditPaymentMethod::class, ['record' => $method->getKey()])
            ->fillForm([
                'account_details' => [
                    'bank_name' => 'Zanaco',
                    'account_name' => 'Car Hire Ltd',
                    'account_number' => '1234567890',
                ],
                'instructions_template' => 'Send :amount to :account_number, swift :swift_code, ref :reference.',
            ])
            ->call('save')
            ->assertHasFormErrors(['instructions_template']);
    }

    public function test_a_template_using_a_supplied_detail_is_accepted(): void
    {
        $method = $this->bankTransfer();

        Livewire::actingAs($this->admin())
            ->test(EditPaymentMethod::class, ['record' => $method->getKey()])
            ->fillForm([
                'account_details' => [
                    'bank_name' => 'Zanaco',
                    'account_name' => 'Car Hire Ltd',
                    'account_number' => '1234567890',
                    // An extra the adapter does not require, used below.
                    'swift_code' => 'ZANAZMLU',
                ],
                'instructions_template' => 'Send :amount to :account_number, swift :swift_code, ref :reference.',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertStringContainsString(':swift_code', (string) $method->refresh()->instructions_template);
    }

    public function test_the_universal_merge_fields_are_always_accepted(): void
    {
        $cash = PaymentMethod::query()->where('code', 'cash')->firstOrFail();

        Livewire::actingAs($this->admin())
            ->test(EditPaymentMethod::class, ['record' => $cash->getKey()])
            ->fillForm([
                'instructions_template' => 'Pay :amount by :deadline quoting :reference for :method.',
            ])
            ->call('save')
            ->assertHasNoFormErrors();
    }

    private function bankTransfer(): PaymentMethod
    {
        return PaymentMethod::query()->where('code', 'bank_transfer')->firstOrFail();
    }

    private function admin(): User
    {
        return User::factory()->withRole(StaffRole::SuperAdmin)->create();
    }
}
