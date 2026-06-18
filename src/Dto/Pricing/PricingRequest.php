<?php

namespace App\Dto\Pricing;

final readonly class PricingRequest
{
    public function __construct(
        public float $distanceKm,
        public int $durationMinutes,
        public string $serviceType,
        public string $vehicleType,
        public ?int $zoneId,
        public \DateTimeImmutable $date
    ) {
    }
}
