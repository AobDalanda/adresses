<?php

declare(strict_types=1);

namespace App\Service\Tracking;

use App\Entity\DriverLocation;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class DeliveryTrackingService
{
    private const ACTIVE_STATUSES = ['ASSIGNED', 'PICKED_UP', 'IN_TRANSIT'];

    public function __construct(private Connection $db)
    {
    }

    /** @return array<string, mixed> */
    public function stateForCustomer(int $customerId, string $deliveryPublicId): array
    {
        $delivery = $this->db->fetchAssociative(
            <<<'SQL'
                SELECT public_id, assigned_driver_id, status
                FROM delivery_order
                WHERE public_id = :publicId
                  AND customer_id = :customerId
                LIMIT 1
                SQL,
            ['publicId' => $deliveryPublicId, 'customerId' => $customerId],
        );
        if ($delivery === false) {
            throw new \OutOfBoundsException('DELIVERY_NOT_FOUND');
        }
        if (
            $delivery['assigned_driver_id'] === null
            || !in_array((string) $delivery['status'], self::ACTIVE_STATUSES, true)
        ) {
            throw new \DomainException('TRACKING_NOT_ACTIVE');
        }

        $driverId = (int) $delivery['assigned_driver_id'];
        $location = $this->db->fetchAssociative(
            <<<'SQL'
                SELECT latitude, longitude, accuracy, speed, heading, created_at
                FROM driver_location
                WHERE driver_id = :driverId
                ORDER BY created_at DESC, id DESC
                LIMIT 1
                SQL,
            ['driverId' => $driverId],
        );

        return [
            'deliveryId' => (string) $delivery['public_id'],
            'driverId' => $driverId,
            'status' => (string) $delivery['status'],
            'topic' => $this->topic((string) $delivery['public_id']),
            'location' => $location === false ? null : [
                'latitude' => (float) $location['latitude'],
                'longitude' => (float) $location['longitude'],
                'accuracy' => (float) $location['accuracy'],
                'speed' => $location['speed'] !== null ? (float) $location['speed'] : null,
                'heading' => $location['heading'] !== null ? (float) $location['heading'] : null,
                'timestamp' => (new \DateTimeImmutable((string) $location['created_at']))->getTimestamp(),
            ],
        ];
    }

    public function recordLocationEvents(DriverLocation $location): int
    {
        $deliveries = $this->db->fetchAllAssociative(
            <<<'SQL'
                SELECT public_id
                FROM delivery_order
                WHERE assigned_driver_id = :driverId
                  AND status IN ('ASSIGNED', 'PICKED_UP', 'IN_TRANSIT')
                SQL,
            ['driverId' => $location->getDriverId()],
        );

        foreach ($deliveries as $delivery) {
            $payload = [
                'deliveryId' => (string) $delivery['public_id'],
                'driverId' => $location->getDriverId(),
                'latitude' => $location->getLatitude(),
                'longitude' => $location->getLongitude(),
                'accuracy' => $location->getAccuracy(),
                'speed' => $location->getSpeed(),
                'heading' => $location->getHeading(),
                'timestamp' => $location->getCreatedAt()->getTimestamp(),
            ];
            $this->db->executeStatement(
                <<<'SQL'
                    INSERT INTO outbox_event (
                        id, aggregate_type, aggregate_id, event_name, payload,
                        occurred_at, attempts
                    )
                    VALUES (
                        :id, 'delivery_order', :aggregateId, 'delivery.location.updated',
                        CAST(:payload AS jsonb), now(), 0
                    )
                    SQL,
                [
                    'id' => Uuid::v7()->toRfc4122(),
                    'aggregateId' => (string) $delivery['public_id'],
                    'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
                ],
            );
        }

        return count($deliveries);
    }

    public function topic(string $deliveryPublicId): string
    {
        return sprintf('delivery/%s/location', $deliveryPublicId);
    }
}
