<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\DeliveryOrderNotificationPublisher;
use App\Service\NearbyDriverMatcher;
use App\Service\PushClientInterface;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

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

    public function testNotificationIsPersistedBeforeAnyMercureOrFcmAttempt(): void
    {
        $notificationPersisted = false;
        $db = $this->createMock(Connection::class);
        $db->method('fetchAllAssociative')->willReturn([[
            'driver_id' => 42,
            'online' => true,
            'effective_online' => true,
            'location_age_seconds' => 12,
            'accuracy_meters' => 8.0,
            'distance_meters' => 720,
            'service_compatible' => true,
            'vehicle_compatible' => true,
            'country_compatible' => true,
            'account_validated' => true,
        ]]);
        $db->method('executeStatement')->willReturnCallback(
            static function (string $sql) use (&$notificationPersisted): int {
                if (str_contains($sql, 'INSERT INTO user_notification')) {
                    $notificationPersisted = true;
                }

                return 1;
            }
        );
        $db->method('fetchOne')->willReturnCallback(static function (string $sql): string {
            return str_contains($sql, 'mercure_status') ? 'PENDING' : 'PENDING';
        });
        $db->method('fetchFirstColumn')->willReturn(['active-fcm-token']);

        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::once())->method('publish')->willReturnCallback(
            static function (Update $update) use (&$notificationPersisted): string {
                self::assertTrue($notificationPersisted, 'Mercure was called before notification persistence.');
                $payload = json_decode($update->getData(), true, flags: JSON_THROW_ON_ERROR);
                self::assertSame('delivery_order.created', $payload['type']);
                self::assertSame('018f6f1e-8f1c-7d9a-9e8f-3c4b8e5f6a7b', $payload['deliveryId']);
                self::assertSame(720, $payload['distanceMeters']);

                return 'mercure-event-id';
            }
        );
        $push = $this->createMock(PushClientInterface::class);
        $push->expects(self::once())->method('send')->willReturnCallback(
            static function (string $token, string $title, string $body, array $payload) use (&$notificationPersisted): void {
                self::assertTrue($notificationPersisted, 'FCM was called before notification persistence.');
                self::assertSame('active-fcm-token', $token);
                self::assertNotEmpty($payload['notificationId']);
                self::assertSame('Maison', $payload['pickupAddress']);
                self::assertSame('Bureau', $payload['dropoffAddress']);
            }
        );

        $matcher = new NearbyDriverMatcher($db, new NullLogger());
        $publisher = new DeliveryOrderNotificationPublisher($hub, new NullLogger(), $db, $push, 1000, $matcher);
        $publisher->handleQueuedDelivery('018f6f1e-8f1c-7d9a-9e8f-3c4b8e5f6a7b', [
            'id' => '018f6f1e-8f1c-7d9a-9e8f-3c4b8e5f6a7b',
            'pickupAddress' => [
                'displayLabel' => 'Maison',
                'latitude' => 48.08,
                'longitude' => -1.66,
                'countryCode' => 'FR',
            ],
            'dropoffAddress' => ['displayLabel' => 'Bureau'],
            'serviceType' => 'STANDARD',
            'vehicleType' => 'MOTO',
        ]);

        self::assertTrue($notificationPersisted);
    }

    public function testFcmIsAttemptedWhenMercureDeliveryFails(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchAllAssociative')->willReturn([[
            'driver_id' => 43,
            'online' => true,
            'effective_online' => true,
            'location_age_seconds' => 30,
            'accuracy_meters' => 15.0,
            'distance_meters' => 42,
            'service_compatible' => true,
            'vehicle_compatible' => true,
            'country_compatible' => true,
            'account_validated' => true,
        ]]);
        $db->method('executeStatement')->willReturn(1);
        $db->method('fetchOne')->willReturn('PENDING');
        $db->method('fetchFirstColumn')->willReturn(['active-fcm-token']);

        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::once())
            ->method('publish')
            ->willThrowException(new \RuntimeException('Mercure unavailable'));
        $push = $this->createMock(PushClientInterface::class);
        $push->expects(self::once())
            ->method('send');

        $publisher = new DeliveryOrderNotificationPublisher(
            $hub,
            new NullLogger(),
            $db,
            $push,
            1000,
            new NearbyDriverMatcher($db, new NullLogger()),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Mercure unavailable');
        $publisher->handleQueuedDelivery('01a0df23-7593-7b50-9fec-44f16fb30b64', [
            'id' => '01a0df23-7593-7b50-9fec-44f16fb30b64',
            'pickupAddress' => [
                'displayLabel' => 'Maison',
                'latitude' => 48.039179881549,
                'longitude' => -1.5393593162298,
                'countryCode' => 'FR',
            ],
            'dropoffAddress' => ['displayLabel' => 'Bureau'],
            'serviceType' => 'STANDARD',
            'vehicleType' => 'MOTO',
        ]);
    }
}
