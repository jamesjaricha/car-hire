<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Refund;
use App\Models\RefundDisbursement;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RefundDisbursement>
 *
 * Only ever one per refund — the table has a unique key on `refund_id`, which
 * is spec §9.3's "never disbursed twice" made structural. A test creating a
 * second is testing that constraint, and should expect it to fail.
 */
final class RefundDisbursementFactory extends Factory
{
    protected $model = RefundDisbursement::class;

    public function definition(): array
    {
        return [
            'refund_id' => Refund::factory(),
            'disbursed_by_user_id' => User::factory(),
            'branch_id' => null,
            'amount_disbursed' => '2160.00',
            // §9.3's proof. Never blank — a refund nobody can evidence is the
            // one that gets queried.
            'disbursement_reference' => 'MM-'.$this->faker->unique()->numerify('######'),
            'disbursed_at' => CarbonImmutable::now(),
            'notes' => null,
        ];
    }

    public function forRefund(Refund $refund): self
    {
        return $this->state(fn (): array => [
            'refund_id' => $refund->getKey(),
            'amount_disbursed' => $refund->amount,
        ]);
    }

    public function disbursedBy(User $user): self
    {
        return $this->state(fn (): array => [
            'disbursed_by_user_id' => $user->getKey(),
            'branch_id' => $user->branch_id,
        ]);
    }
}
