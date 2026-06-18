<?php

namespace App\Dto\Pricing;

final readonly class PricingResult
{
    /**
     * @param list<PricingSurchargeResult> $surcharges
     */
    public function __construct(
        public float $distance,
        public int $duration,
        public int $basePrice,
        public int $distancePrice,
        public array $surcharges,
        public int $totalPrice,
        public string $currency,
        public int $pricingModelId,
        public int $pricingRuleId
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'distance' => $this->distance,
            'duration' => $this->duration,
            'base_price' => $this->basePrice,
            'distance_price' => $this->distancePrice,
            'surcharges' => array_map(
                static fn (PricingSurchargeResult $surcharge): array => $surcharge->toArray(),
                $this->surcharges
            ),
            'total_price' => $this->totalPrice,
            'currency' => $this->currency,
            'pricing_model_id' => $this->pricingModelId,
            'pricing_rule_id' => $this->pricingRuleId,
        ];
    }
}
