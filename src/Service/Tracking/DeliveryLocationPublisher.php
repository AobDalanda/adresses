<?php

declare(strict_types=1);

namespace App\Service\Tracking;

use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final readonly class DeliveryLocationPublisher
{
    public function __construct(private HubInterface $hub)
    {
    }

    /** @param array<string, mixed> $payload */
    public function publish(array $payload): void
    {
        $deliveryId = $payload['deliveryId'] ?? null;
        if (!is_string($deliveryId) || $deliveryId === '') {
            throw new \UnexpectedValueException('Evenement de position sans deliveryId.');
        }

        $this->hub->publish(new Update(
            sprintf('delivery/%s/location', $deliveryId),
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
            private: true,
        ));
    }
}
