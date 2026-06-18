<?php

namespace App\Dto\Pricing;

final readonly class PricingSurchargeResult
{
    public function __construct(
        public string $name,
        public string $type,
        public float $value,
        public int $amount
    ) {
    }

    /**
     * @return array{name: string, type: string, value: float, amount: int}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'value' => $this->value,
            'amount' => $this->amount,
        ];
    }
}
