<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentMethodCode;
use App\Enums\PaymentMethodType;
use Database\Factories\PaymentMethodFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A way for a customer to pay. Spec §3 and §4.
 *
 * Availability is decided in two places and BOTH must allow it: the `enabled`
 * column, which the operator controls from the admin panel, and a deployment
 * feature flag in configuration. The flag wins — it exists so a method can be
 * killed without database access if, say, a mobile money merchant number is
 * compromised.
 *
 * Do not check `enabled` directly when deciding whether to accept a payment.
 * Use `isOfferable()`, or better, go through PaymentMethodService, which also
 * applies the short-notice and lead-time rules.
 */
final class PaymentMethod extends Model
{
    /** @use HasFactory<PaymentMethodFactory> */
    use HasFactory;

    protected $fillable = [
        'code',
        'label',
        'type',
        'enabled',
        'display_order',
        'requires_manual_confirmation',
        'instructions_template',
        'account_details',
        'feature_flag',
        'min_lead_time_hours',
        'hold_duration_hours',
    ];

    protected function casts(): array
    {
        return [
            'code' => PaymentMethodCode::class,
            'type' => PaymentMethodType::class,
            'enabled' => 'boolean',
            'display_order' => 'integer',
            'requires_manual_confirmation' => 'boolean',
            'account_details' => 'array',
            'min_lead_time_hours' => 'integer',
            'hold_duration_hours' => 'integer',
        ];
    }

    /**
     * Whether this method may be offered at all, ignoring timing.
     */
    public function isOfferable(): bool
    {
        if (! $this->enabled) {
            return false;
        }

        return (bool) config($this->code->configKey(), false);
    }

    /**
     * Whether the customer pays at the counter rather than remotely.
     */
    public function isSettledAtBranch(): bool
    {
        return $this->type->isSettledAtBranch();
    }

    /**
     * Methods the operator has switched on. Still subject to the config flag
     * and to timing rules — this scope is a narrowing, not an answer.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeInDisplayOrder(Builder $query): Builder
    {
        return $query->orderBy('display_order')->orderBy('id');
    }
}
