<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\StaffRole;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 *
 * A user is a member of staff, never a customer.
 *
 * `operator_id` and `branch_id` are left null by default, which is the Super
 * Admin shape. Tests that care about branch scoping should say so explicitly
 * with atBranch() rather than relying on a generated branch, because a fixture
 * that quietly invents its own branch is how a branch-scoping test comes to
 * pass without testing anything.
 */
final class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => self::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'operator_id' => null,
            'branch_id' => null,
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Post the user to a branch, and to that branch's operator.
     *
     * Both are set together on purpose. A user attached to a branch belonging
     * to one operator while carrying another operator's id is a state the
     * permission checks should never have to reason about.
     */
    public function atBranch(Branch $branch): static
    {
        return $this->state(fn (array $attributes): array => [
            'branch_id' => $branch->getKey(),
            'operator_id' => $branch->operator_id,
        ]);
    }

    /**
     * Give the created user a role.
     *
     * The roles must exist, so a test using this has to have run
     * RolesAndPermissionsSeeder first. That is intentional — assigning a role
     * that was never seeded should fail loudly rather than produce a user with
     * no permissions and a mystifying refusal later.
     */
    public function withRole(StaffRole $role): static
    {
        return $this->afterCreating(static function (User $user) use ($role): void {
            $user->assignRole($role);
        });
    }
}
