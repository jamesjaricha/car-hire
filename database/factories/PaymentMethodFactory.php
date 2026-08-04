<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PaymentMethodCode;
use App\Models\PaymentMethod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentMethod>
 */
final class PaymentMethodFactory extends Factory
{
    protected $model = PaymentMethod::class;

    public function definition(): array
    {
        $code = $this->faker->randomElement(PaymentMethodCode::cases());

        return $this->attributesFor($code);
    }

    public function code(PaymentMethodCode $code): self
    {
        return $this->state(fn (): array => $this->attributesFor($code));
    }

    public function disabled(): self
    {
        return $this->state(fn (): array => ['enabled' => false]);
    }

    public function withHoldDuration(int $hours): self
    {
        return $this->state(fn (): array => ['hold_duration_hours' => $hours]);
    }

    public function withMinLeadTime(?int $hours): self
    {
        return $this->state(fn (): array => ['min_lead_time_hours' => $hours]);
    }

    /**
     * @return array<string, mixed>
     */
    private function attributesFor(PaymentMethodCode $code): array
    {
        return [
            'code' => $code,
            'label' => $code->label(),
            'type' => $code->type(),
            // Card methods are not enabled at MVP.
            'enabled' => $code->type()->requiresManualConfirmation(),
            'display_order' => 0,
            'requires_manual_confirmation' => $code->type()->requiresManualConfirmation(),
            'instructions_template' => null,
            'account_details' => null,
            'feature_flag' => $code->featureFlagName(),
            'min_lead_time_hours' => null,
            'hold_duration_hours' => $code->defaultHoldDurationHours(),
        ];
    }
}
