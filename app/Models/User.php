<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StaffRole;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

/**
 * A member of staff.
 *
 * Customers are not users. They are `Customer` records, created from an email
 * and a phone number at checkout, and most of them never have a password at
 * all — spec §1.4 makes guest checkout the default. Anything that authenticates
 * here is someone who works for the operator.
 *
 * `branch_id` and `operator_id` are both nullable, and null means something in
 * each case rather than being an unfilled field. See the migration.
 */
#[Fillable(['name', 'email', 'password', 'operator_id', 'branch_id'])]
#[Hidden(['password', 'remember_token'])]
final class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /** @return BelongsTo<Operator, $this> */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Whether this person may open the staff panel at all.
     *
     * WHY THIS EXISTS, IN FILAMENT'S OWN WORDS
     *
     * Without the FilamentUser contract, "all authenticated users can access
     * your panel when APP_ENV is not local". Authentication is not
     * authorisation, and this application spent a whole phase establishing that
     * — spec §12 grants permissions per action and per payment method, and a
     * panel that lets anyone with a password read every booking and payment
     * makes that work irrelevant.
     *
     * The bar here is only "is this person staff at all". What they may then DO
     * is decided per action by the permissions, not by this method. This is the
     * front door, not the whole building.
     *
     * Fails closed twice over: an unrecognised panel is refused, and a user
     * holding no role we recognise is refused. `customers` are not `users`, so
     * a customer record can never reach this in any case.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if ($panel->getId() !== 'admin') {
            return false;
        }

        return $this->staffRoles() !== [];
    }

    /**
     * Whether this user holds the given role.
     *
     * A thin wrapper over the package's `hasRole()`, which does accept a backed
     * enum directly. It exists so that callers name a StaffRole case and cannot
     * pass a string that quietly matches nothing.
     */
    public function hasStaffRole(StaffRole $role): bool
    {
        return $this->hasRole($role);
    }

    /**
     * The user's roles, as enum cases.
     *
     * Roles whose stored name is not one of ours are skipped rather than
     * throwing: a role added by hand in the admin panel is not a reason for the
     * confirmation screen to fall over.
     *
     * @return list<StaffRole>
     */
    public function staffRoles(): array
    {
        // Explicit load rather than a lazy one — Model::shouldBeStrict() is on
        // outside production and would refuse the implicit read.
        $this->loadMissing('roles');

        return array_values(array_filter(array_map(
            static fn (string $name): ?StaffRole => StaffRole::tryFrom($name),
            $this->roles->pluck('name')->all(),
        )));
    }

    /**
     * Whether every role this user holds is exempt from the per-branch cash
     * confirmation setting (spec §15.12).
     *
     * A user with no recognised role is not exempt. Failing closed is the point:
     * this decides whether someone may accept money.
     */
    public function isExemptFromCashConfirmationSetting(): bool
    {
        $roles = $this->staffRoles();

        if ($roles === []) {
            return false;
        }

        foreach ($roles as $role) {
            if ($role->isExemptFromCashConfirmationSetting()) {
                return true;
            }
        }

        return false;
    }
}
