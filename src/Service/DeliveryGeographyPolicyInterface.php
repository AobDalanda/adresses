<?php

declare(strict_types=1);

namespace App\Service;

interface DeliveryGeographyPolicyInterface
{
    public function assertDeliveryAllowed(?string $pickupCountry, ?string $dropoffCountry): void;
}
