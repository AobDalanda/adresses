<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\DeliveryOrderNotificationPublisher;
use App\Service\PushClientInterface;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;

final class DeliveryOrderNotificationPublisherTest extends TestCase
{
    public function testCreationOnlyQueuesDurableOutboxEvent(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())
            ->method('executeStatement')
            ->with(
                self::stringContains('INSERT INTO outbox_event'),
                self::callback(static fn (array $parameters): bool =>
                    $parameters['eventName'] === 'delivery_order.created'
                    && $parameters['deliveryId'] === '018f6f1e-8f1c-7d9a-9e8f-3c4b8e5f6a7b'
                    && str_contains($parameters['payload'], 'Maison')
                ),
            )
            ->willReturn(1);

        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::never())->method('publish');
        $push = $this->createMock(PushClientInterface::class);
        $push->expects(self::never())->method('send');

        $publisher = new DeliveryOrderNotificationPublisher($hub, new NullLogger(), $db, $push);

        self::assertTrue($publisher->publishNewDeliveryOrder([
            'id' => '018f6f1e-8f1c-7d9a-9e8f-3c4b8e5f6a7b',
            'pickupAddress' => ['displayLabel' => 'Maison', 'latitude' => 48.08, 'longitude' => -1.66],
        ]));
    }

    public function testInvalidDeliveryIdFailsClosedWithoutNetworkOrDatabaseWrite(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('executeStatement');
        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::never())->method('publish');
        $push = $this->createMock(PushClientInterface::class);
        $push->expects(self::never())->method('send');

        $publisher = new DeliveryOrderNotificationPublisher($hub, new NullLogger(), $db, $push);

        self::assertFalse($publisher->publishNewDeliveryOrder(['id' => 'invalid']));
    }

    public function testQueueFailureDoesNotBreakDeliveryCreationFlow(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('executeStatement')->willThrowException(new \RuntimeException('Database unavailable'));
        $publisher = new DeliveryOrderNotificationPublisher(
            $this->createMock(HubInterface::class),
            new NullLogger(),
            $db,
            $this->createMock(PushClientInterface::class),
        );

        self::assertFalse($publisher->publishNewDeliveryOrder([
            'id' => '018f6f1e-8f1c-7d9a-9e8f-3c4b8e5f6a7b',
        ]));
    }
}
