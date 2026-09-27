<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;

final readonly class DeliveryOrderNotificationPublisher implements DeliveryOrderNotificationPublisherInterface
{
    private const TYPE = 'delivery_order.created';

    public function __construct(
        private HubInterface $hub,
        private LoggerInterface $logger,
        private Connection $db,
        private PushClientInterface $push,
        private int $maxDistanceMeters = 1000,
        private ?NearbyDriverMatcher $matcher = null,
    ) {
    }

    /** @param array<string, mixed> $delivery */
    public function publishNewDeliveryOrder(array $delivery): bool
    {
        $deliveryId = (string) ($delivery['id'] ?? '');
        if (!Uuid::isValid($deliveryId)) {
            return false;
        }

        try {
            $inserted = $this->db->executeStatement(
                <<<'SQL'
                    INSERT INTO outbox_event (
                        id, aggregate_type, aggregate_id, event_name, payload,
                        occurred_at, published_at, attempts
                    ) VALUES (
                        :id, 'delivery_order', :deliveryId, :eventName, CAST(:payload AS jsonb),
                        now(), NULL, 0
                    ) ON CONFLICT (id) DO NOTHING
                    SQL,
                [
                    'id' => $deliveryId,
                    'deliveryId' => $deliveryId,
                    'eventName' => self::TYPE,
                    'payload' => json_encode($delivery, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                ],
            );

            if ($inserted !== 1) {
                $this->logger->error('Delivery notification outbox event was not inserted.', ['deliveryId' => $deliveryId]);

                return false;
            }

            return true;
        } catch (\Throwable $exception) {
            $this->logger->error('Delivery notification enqueue failed.', ['deliveryId' => $deliveryId, 'exception' => $exception]);

            return false;
        }
    }

    /** @param array<string, mixed> $delivery */
    public function handleQueuedDelivery(string $eventId, array $delivery): void
    {
        $pickup = is_array($delivery['pickupAddress'] ?? null) ? $delivery['pickupAddress'] : [];
        $deliveryId = (string) ($delivery['id'] ?? '');
        if ($this->matcher === null) {
            throw new \LogicException('NearbyDriverMatcher is required by the outbox worker.');
        }
        $drivers = $this->matcher->findEligibleDrivers(
            $this->coordinate($pickup['latitude'] ?? null, -90, 90),
            $this->coordinate($pickup['longitude'] ?? null, -180, 180),
            (string) ($delivery['serviceType'] ?? ''),
            (string) ($delivery['vehicleType'] ?? ''),
            $this->maxDistanceMeters,
            is_string($pickup['countryCode'] ?? null) ? $pickup['countryCode'] : null,
            $deliveryId,
        );

        foreach ($drivers as $driver) {
            $notificationId = $this->persistNotification($eventId, $driver['driverId'], $deliveryId, $delivery, $driver['distanceMeters']);
            $payload = $this->payload($notificationId, $delivery, $driver['distanceMeters']);
            $deliveryErrors = [];
            try {
                $this->deliverMercure($notificationId, $driver['driverId'], $payload + ['presentation' => 'silent']);
            } catch (\Throwable $exception) {
                $deliveryErrors[] = $exception;
            }
            try {
                $this->deliverFcm($notificationId, $driver['driverId'], $payload + ['presentation' => 'system']);
            } catch (\Throwable $exception) {
                $deliveryErrors[] = $exception;
            }
            if ($deliveryErrors !== []) {
                if (count($deliveryErrors) < 2) {
                    continue;
                }
                throw new \RuntimeException(
                    implode(' | ', array_map(
                        static fn (\Throwable $exception): string => $exception->getMessage(),
                        $deliveryErrors,
                    )),
                    previous: $deliveryErrors[0],
                );
            }
        }
    }

    public static function topicForDriver(int $driverId): string
    {
        return sprintf(self::NEW_DELIVERY_ORDER_TOPIC_TEMPLATE, $driverId);
    }

    /** @param array<string, mixed> $delivery */
    private function persistNotification(string $eventId, int $userId, string $deliveryId, array $delivery, int $distanceMeters): string
    {
        $id = Uuid::v7()->toRfc4122();
        $payload = $this->payload($id, $delivery, $distanceMeters);
        $inserted = $this->db->executeStatement(
            <<<'SQL'
                INSERT INTO user_notification (
                    id, user_id, source_event_id, delivery_id, type, title, body, data,
                    push_status, mercure_status, created_at
                ) VALUES (
                    :id, :userId, :eventId, :deliveryId, :type, :title, :body, CAST(:data AS jsonb),
                    'PENDING', 'PENDING', now()
                ) ON CONFLICT (source_event_id, user_id) DO NOTHING
                SQL,
            [
                'id' => $id, 'userId' => $userId, 'eventId' => $eventId, 'deliveryId' => $deliveryId,
                'type' => self::TYPE, 'title' => 'Nouvelle livraison',
                'body' => sprintf('%s → %s', $payload['pickupAddress'], $payload['dropoffAddress']),
                'data' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ],
        );
        if ($inserted === 1) {
            return $id;
        }

        $existing = $this->db->fetchOne(
            'SELECT id FROM user_notification WHERE source_event_id = :eventId AND user_id = :userId',
            ['eventId' => $eventId, 'userId' => $userId],
        );
        if (!is_string($existing) || $existing === '') {
            throw new \RuntimeException('Unable to recover idempotent delivery notification.');
        }

        return $existing;
    }

    /** @param array<string, mixed> $delivery @return array<string, mixed> */
    private function payload(string $notificationId, array $delivery, int $distanceMeters): array
    {
        $deliveryId = (string) ($delivery['id'] ?? '');

        return [
            'type' => self::TYPE,
            'notificationId' => $notificationId,
            'deliveryId' => $deliveryId,
            'status' => (string) ($delivery['status'] ?? ''),
            'collapseKey' => 'delivery_order.' . $deliveryId,
            'notificationGroup' => 'delivery_order',
            'pickupAddress' => $this->addressLabel($delivery['pickupAddress'] ?? null, 'Départ'),
            'dropoffAddress' => $this->addressLabel($delivery['dropoffAddress'] ?? null, 'Destination'),
            'distanceMeters' => $distanceMeters,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function deliverMercure(string $notificationId, int $driverId, array $payload): void
    {
        if ($this->db->fetchOne('SELECT mercure_status FROM user_notification WHERE id = :id', ['id' => $notificationId]) === 'SENT') {
            return;
        }
        try {
            $this->hub->publish(new Update(self::topicForDriver($driverId), json_encode($payload, JSON_THROW_ON_ERROR), private: true));
            $this->setMercureResult($notificationId, 'SENT', null);
        } catch (\Throwable $exception) {
            $this->setMercureResult($notificationId, 'FAILED', $exception->getMessage());
            throw $exception;
        }
    }

    /** @param array<string, mixed> $payload */
    private function deliverFcm(string $notificationId, int $driverId, array $payload): void
    {
        if (in_array($this->db->fetchOne('SELECT push_status FROM user_notification WHERE id = :id', ['id' => $notificationId]), ['SENT', 'SKIPPED'], true)) {
            return;
        }
        $tokens = array_values(array_unique(array_filter(
            array_map(
                static fn (mixed $token): string => trim((string) $token),
                $this->db->fetchFirstColumn('SELECT token FROM user_push_device WHERE user_id = :userId AND enabled = TRUE', ['userId' => $driverId]),
            ),
            static fn (string $token): bool => $token !== '',
        )));
        if ($tokens === []) {
            $this->setPushResult($notificationId, 'SKIPPED', 0, null);
            return;
        }

        $sent = 0;
        $temporaryErrors = [];
        foreach ($tokens as $token) {
            try {
                $this->push->send((string) $token, 'Nouvelle livraison', sprintf('%s → %s', $payload['pickupAddress'], $payload['dropoffAddress']), $payload);
                ++$sent;
            } catch (\Throwable $exception) {
                if ($this->isPermanentTokenFailure($exception)) {
                    $this->db->executeStatement('UPDATE user_push_device SET enabled = FALSE, updated_at = now() WHERE token_hash = :hash', ['hash' => hash('sha256', (string) $token)]);
                } else {
                    $temporaryErrors[] = $exception->getMessage();
                }
            }
        }
        $status = $sent === count($tokens) ? 'SENT' : ($sent > 0 ? 'PARTIAL' : 'FAILED');
        $error = $temporaryErrors === [] ? null : mb_substr(implode(' | ', $temporaryErrors), 0, 4000);
        $this->setPushResult($notificationId, $status, count($tokens), $error);
        if ($temporaryErrors !== [] && $sent === 0) {
            throw new \RuntimeException($error ?? 'Temporary FCM failure.');
        }
    }

    private function setMercureResult(string $id, string $status, ?string $error): void
    {
        $this->db->executeStatement('UPDATE user_notification SET mercure_status = :status, mercure_last_error = :error WHERE id = :id', ['id' => $id, 'status' => $status, 'error' => $error]);
    }

    private function setPushResult(string $id, string $status, int $attempts, ?string $error): void
    {
        $this->db->executeStatement('UPDATE user_notification SET push_status = :status, push_attempts = push_attempts + :attempts, push_last_error = :error WHERE id = :id', ['id' => $id, 'status' => $status, 'attempts' => $attempts, 'error' => $error]);
    }

    private function isPermanentTokenFailure(\Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());
        return str_contains($message, 'not registered') || str_contains($message, 'notregistered') || str_contains($message, 'unregistered')
            || str_contains($message, 'requested entity was not found') || str_contains($message, 'token not found');
    }

    private function coordinate(mixed $value, float $minimum, float $maximum): float
    {
        if (!is_numeric($value) || !is_finite((float) $value) || (float) $value < $minimum || (float) $value > $maximum) {
            throw new \UnexpectedValueException('Invalid pickup coordinates.');
        }
        return (float) $value;
    }

    private function addressLabel(mixed $address, string $fallback): string
    {
        if (!is_array($address) || !is_string($address['displayLabel'] ?? null) || trim($address['displayLabel']) === '') {
            return $fallback;
        }
        return mb_substr(trim($address['displayLabel']), 0, 160);
    }
}
