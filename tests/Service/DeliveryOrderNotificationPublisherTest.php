<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\DeliveryOrderNotificationPublisher;
use App\Service\DeliveryOrderNotificationPublisherInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final class DeliveryOrderNotificationPublisherTest extends TestCase
{
    public function testPublishesNewDeliveryOrderToDriversTopic(): void
    {
        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::once())
            ->method('publish')
            ->with(self::callback(static function (Update $update): bool {
                $payload = json_decode($update->getData(), true, 512, JSON_THROW_ON_ERROR);

                return $update->getTopics() === [DeliveryOrderNotificationPublisherInterface::NEW_DELIVERY_ORDER_TOPIC]
                    && $update->isPrivate()
                    && $payload['type'] === 'delivery_order.created'
                    && $payload['delivery']['id'] === '018f6f1e-8f1c-7d9a-9e8f-3c4b8e5f6a7b'
                    && $payload['delivery']['pricing']['totalAmount'] === 1500
                    && !array_key_exists('recipient', $payload['delivery']);
            }))
            ->willReturn('event-id');

        $publisher = new DeliveryOrderNotificationPublisher($hub, new NullLogger());

        self::assertTrue($publisher->publishNewDeliveryOrder([
            'id' => '018f6f1e-8f1c-7d9a-9e8f-3c4b8e5f6a7b',
            'status' => 'QUOTED',
            'pickupAddress' => ['id' => 12, 'displayLabel' => 'Maison'],
            'dropoffAddress' => ['id' => 45, 'displayLabel' => 'Bureau'],
            'recipient' => ['name' => 'Mamadou Diallo', 'phone' => '224620123456'],
            'pricing' => [
                'distanceKm' => 8.4,
                'durationMinutes' => 26,
                'totalAmount' => 1500,
                'currency' => 'GNF',
            ],
            'scheduledAt' => null,
            'createdAt' => '2026-06-22T09:30:00+00:00',
        ]));
    }

    public function testMercureFailureDoesNotBreakDeliveryCreationFlow(): void
    {
        $hub = $this->createMock(HubInterface::class);
        $hub->method('publish')->willThrowException(new \RuntimeException('Hub unavailable'));

        $publisher = new DeliveryOrderNotificationPublisher($hub, new NullLogger());

        self::assertFalse($publisher->publishNewDeliveryOrder([
            'id' => '018f6f1e-8f1c-7d9a-9e8f-3c4b8e5f6a7b',
        ]));
    }
}
