<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final readonly class DeliveryOrderNotificationPublisher implements DeliveryOrderNotificationPublisherInterface
{
    public function __construct(
        private HubInterface $hub,
        private LoggerInterface $logger
    ) {
    }

    /**
     * @param array<string, mixed> $delivery
     */
    public function publishNewDeliveryOrder(array $delivery): bool
    {
        $payload = [
            'type' => 'delivery_order.created',
            'delivery' => [
                'id' => $delivery['id'] ?? null,
                'status' => $delivery['status'] ?? null,
                'pickupAddress' => $delivery['pickupAddress'] ?? null,
                'dropoffAddress' => $delivery['dropoffAddress'] ?? null,
                'pricing' => [
                    'totalAmount' => $delivery['pricing']['totalAmount'] ?? null,
                    'currency' => $delivery['pricing']['currency'] ?? null,
                    'distanceKm' => $delivery['pricing']['distanceKm'] ?? null,
                    'durationMinutes' => $delivery['pricing']['durationMinutes'] ?? null,
                ],
                'scheduledAt' => $delivery['scheduledAt'] ?? null,
                'createdAt' => $delivery['createdAt'] ?? null,
            ],
        ];

        try {
            $this->hub->publish(new Update(
                self::NEW_DELIVERY_ORDER_TOPIC,
                json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
                private: true
            ));

            $this->logger->info('Mercure new delivery order notification published', [
                'deliveryId' => $delivery['id'] ?? null,
                'topic' => self::NEW_DELIVERY_ORDER_TOPIC,
            ]);

            return true;
        } catch (\Throwable $exception) {
            $this->logger->error('Mercure new delivery order notification failed', [
                'deliveryId' => $delivery['id'] ?? null,
                'topic' => self::NEW_DELIVERY_ORDER_TOPIC,
                'exception' => $exception,
            ]);

            return false;
        }
    }
}
