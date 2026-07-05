<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\DeliveryOrderNotificationPublisher;
use App\Service\DeliveryOrderNotificationPublisherInterface;
use App\Service\PushClientInterface;
use Doctrine\DBAL\Connection;
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

        $publisher = new DeliveryOrderNotificationPublisher(
            $hub,
            new NullLogger(),
            $this->connectionWithDriverTargets([]),
            $this->createMock(PushClientInterface::class),
        );

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

        $publisher = new DeliveryOrderNotificationPublisher(
            $hub,
            new NullLogger(),
            $this->connectionWithDriverTargets([]),
            $this->createMock(PushClientInterface::class),
        );

        self::assertFalse($publisher->publishNewDeliveryOrder([
            'id' => '018f6f1e-8f1c-7d9a-9e8f-3c4b8e5f6a7b',
        ]));
    }

    public function testPublishesFcmPushToEligibleDrivers(): void
    {
        $hub = $this->createMock(HubInterface::class);
        $hub->method('publish')->willReturn('event-id');

        $push = $this->createMock(PushClientInterface::class);
        $push->expects(self::exactly(2))
            ->method('send')
            ->with(
                self::callback(static fn (string $token): bool => in_array($token, ['token-1', 'token-2'], true)),
                'Nouvelle livraison - 1 500 GNF',
                'Maison -> Bureau · 8,4 km',
                self::callback(static fn (array $data): bool =>
                    $data['type'] === 'delivery_order.created'
                    && $data['deliveryId'] === '018f6f1e-8f1c-7d9a-9e8f-3c4b8e5f6a7b'
                    && $data['status'] === 'QUOTED'
                    && $data['notificationIcon'] === 'ic_stat_delivery'
                    && $data['notificationColor'] === '#0F766E'
                    && is_string($data['notificationId'] ?? null)
                ),
            );

        $publisher = new DeliveryOrderNotificationPublisher(
            $hub,
            new NullLogger(),
            $this->connectionWithDriverTargets([
                ['user_id' => 42, 'token' => 'token-1'],
                ['user_id' => 43, 'token' => 'token-2'],
            ]),
            $push,
        );

        self::assertTrue($publisher->publishNewDeliveryOrder([
            'id' => '018f6f1e-8f1c-7d9a-9e8f-3c4b8e5f6a7b',
            'status' => 'QUOTED',
            'pickupAddress' => ['id' => 12, 'displayLabel' => 'Maison'],
            'dropoffAddress' => ['id' => 45, 'displayLabel' => 'Bureau'],
            'pricing' => [
                'distanceKm' => 8.4,
                'durationMinutes' => 26,
                'totalAmount' => 1500,
                'currency' => 'GNF',
            ],
        ]));
    }

    public function testPersistsInboxNotificationForEligibleDriverWithoutFcmToken(): void
    {
        $hub = $this->createMock(HubInterface::class);
        $hub->method('publish')->willReturn('event-id');

        $executedStatements = [];
        $db = $this->createMock(Connection::class);
        $db->method('fetchAllAssociative')
            ->willReturn([['user_id' => 43, 'token' => null]]);
        $db->method('executeStatement')
            ->willReturnCallback(
                static function (string $sql, array $params = []) use (&$executedStatements): int {
                    $executedStatements[] = [$sql, $params];

                    return 1;
                }
            );

        $push = $this->createMock(PushClientInterface::class);
        $push->expects(self::never())->method('send');

        $publisher = new DeliveryOrderNotificationPublisher($hub, new NullLogger(), $db, $push);

        self::assertTrue($publisher->publishNewDeliveryOrder([
            'id' => '018f6f1e-8f1c-7d9a-9e8f-3c4b8e5f6a7b',
            'status' => 'QUOTED',
            'pickupAddress' => ['id' => 12, 'displayLabel' => 'Maison'],
            'dropoffAddress' => ['id' => 45, 'displayLabel' => 'Bureau'],
            'pricing' => [
                'distanceKm' => 8.4,
                'durationMinutes' => 26,
                'totalAmount' => 1500,
                'currency' => 'GNF',
            ],
        ]));

        self::assertTrue($this->hasStatement($executedStatements, 'INSERT INTO outbox_event'));
        self::assertTrue($this->hasStatement($executedStatements, 'INSERT INTO user_notification'));
        self::assertTrue($this->hasStatement($executedStatements, 'push_status = :status'));
    }

    public function testDisablesStaleFcmTokenAfterPermanentFailure(): void
    {
        $hub = $this->createMock(HubInterface::class);
        $hub->method('publish')->willReturn('event-id');

        $executedStatements = [];
        $db = $this->createMock(Connection::class);
        $db->method('fetchAllAssociative')
            ->willReturn([['user_id' => 43, 'token' => 'stale-token']]);
        $db->method('executeStatement')
            ->willReturnCallback(
                static function (string $sql, array $params = []) use (&$executedStatements): int {
                    $executedStatements[] = [$sql, $params];

                    return 1;
                }
            );

        $push = $this->createMock(PushClientInterface::class);
        $push->method('send')
            ->willThrowException(new \RuntimeException('FCM send failed: Requested entity was not found.'));

        $publisher = new DeliveryOrderNotificationPublisher($hub, new NullLogger(), $db, $push);

        self::assertTrue($publisher->publishNewDeliveryOrder([
            'id' => '018f6f1e-8f1c-7d9a-9e8f-3c4b8e5f6a7b',
            'status' => 'QUOTED',
            'pickupAddress' => ['id' => 12, 'displayLabel' => 'Maison'],
            'dropoffAddress' => ['id' => 45, 'displayLabel' => 'Bureau'],
            'pricing' => [
                'distanceKm' => 8.4,
                'durationMinutes' => 26,
                'totalAmount' => 1500,
                'currency' => 'GNF',
            ],
        ]));

        self::assertTrue($this->hasStatement($executedStatements, 'UPDATE user_push_device'));
        self::assertTrue($this->hasStatement($executedStatements, 'token_hash = :tokenHash'));
    }

    /**
     * @param list<array{user_id: int, token: ?string}> $targets
     */
    private function connectionWithDriverTargets(array $targets): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchAllAssociative')
            ->willReturnCallback(static function (string $sql) use ($targets): array {
                self::assertStringContainsString('FROM user_account account', $sql);
                self::assertStringContainsString('JOIN provider_authorization provider_auth', $sql);

                return $targets;
            });
        $db->method('executeStatement')->willReturn(1);

        return $db;
    }

    /**
     * @param list<array{0: string, 1: array<string, mixed>}> $statements
     */
    private function hasStatement(array $statements, string $needle): bool
    {
        foreach ($statements as [$sql]) {
            if (str_contains($sql, $needle)) {
                return true;
            }
        }

        return false;
    }
}
