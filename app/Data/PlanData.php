<?php

namespace App\Data;

use App\Enums\BillingCycle;
use App\Models\Plan;

/**
 * Cache-safe copy of a plan's pricing. The cache stores plain arrays rather than
 * serialised Eloquent models, so cached entries survive model refactors and never
 * drag relations or connection state along.
 */
final readonly class PlanData
{
    public function __construct(
        public int $id,
        public int $merchantId,
        public string $name,
        public BillingCycle $billingCycle,
        public int $basePriceMinor,
        public int $includedUnits,
        public int $overageRateMicros,
        public bool $isActive,
    ) {}

    public static function fromModel(Plan $plan): self
    {
        return new self(
            id: $plan->id,
            merchantId: $plan->merchant_id,
            name: $plan->name,
            billingCycle: $plan->billing_cycle,
            basePriceMinor: $plan->base_price_minor,
            includedUnits: $plan->included_units,
            overageRateMicros: $plan->overage_rate_micros,
            isActive: $plan->is_active,
        );
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            merchantId: $data['merchant_id'],
            name: $data['name'],
            billingCycle: BillingCycle::from($data['billing_cycle']),
            basePriceMinor: $data['base_price_minor'],
            includedUnits: $data['included_units'],
            overageRateMicros: $data['overage_rate_micros'],
            isActive: $data['is_active'],
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'merchant_id' => $this->merchantId,
            'name' => $this->name,
            'billing_cycle' => $this->billingCycle->value,
            'base_price_minor' => $this->basePriceMinor,
            'included_units' => $this->includedUnits,
            'overage_rate_micros' => $this->overageRateMicros,
            'is_active' => $this->isActive,
        ];
    }
}
