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
    private const NOTIFICATION_TYPE = 'delivery_order.created';
    private const NOTIFICATION_TITLE = 'Nouvelle livraison';
    private const NOTIFICATION_BODY = 'Une nouvelle livraison est disponible.';

    public function __construct(
        private HubInterface $hub,
        private LoggerInterface $logger,
        private Connection $db,
        private PushClientInterface $push,
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

            $sourceEventId = $this->recordDeliveryOutboxEvent($payload);
            $this->publishDriverNotifications($sourceEventId, $payload);

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

    /**
     * @param array<string, mixed> $payload
     */
    private function recordDeliveryOutboxEvent(array $payload): string
    {
        $deliveryId = (string) ($payload['delivery']['id'] ?? '');
        $sourceEventId = Uuid::isValid($deliveryId) ? $deliveryId : Uuid::v7()->toRfc4122();

        $this->db->executeStatement(
            <<<'SQL'
                INSERT INTO outbox_event (
                    id, aggregate_type, aggregate_id, event_name, payload,
                    occurred_at, published_at, attempts
                )
                VALUES (
                    :id, 'delivery_order', :aggregateId, :eventName, CAST(:payload AS jsonb),
                    now(), now(), 1
                )
                ON CONFLICT (id) DO NOTHING
                SQL,
            [
                'id' => $sourceEventId,
                'aggregateId' => $deliveryId,
                'eventName' => self::NOTIFICATION_TYPE,
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ],
        );

        return $sourceEventId;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function publishDriverNotifications(string $sourceEventId, array $payload): void
    {
        $targets = $this->fetchEligibleDriverTargets();

        if ($targets === []) {
            $this->logger->info('No eligible driver registered for new delivery notification', [
                'deliveryId' => $payload['delivery']['id'] ?? null,
            ]);

            return;
        }

        $totalTokens = 0;
        $sent = 0;
        $failed = 0;

        foreach ($targets as $userId => $tokens) {
            $notificationId = $this->persistNotification($sourceEventId, (int) $userId, $payload);
            if ($notificationId === null) {
                continue;
            }

            if ($tokens === []) {
                $this->setPushResult($notificationId, 'SKIPPED', 0, null);
                continue;
            }

            $result = $this->sendPushNotifications($notificationId, $tokens, $payload);
            $totalTokens += count($tokens);
            $sent += $result['sent'];
            $failed += $result['failed'];

            $status = $result['sent'] === count($tokens) ? 'SENT' : ($result['sent'] > 0 ? 'PARTIAL' : 'FAILED');
            $this->setPushResult($notificationId, $status, count($tokens), $result['error']);
        }

        $this->logger->info('Driver FCM new delivery notification completed', [
            'deliveryId' => $payload['delivery']['id'] ?? null,
            'targetedTokens' => $totalTokens,
            'sent' => $sent,
            'failed' => $failed,
        ]);
    }

    /**
     * @return array<int, list<string>>
     */
    private function fetchEligibleDriverTargets(): array
    {
        $rows = $this->db->fetchAllAssociative(
            <<<'SQL'
                SELECT DISTINCT account.id AS user_id, device.token
                FROM user_account account
                LEFT JOIN provider_profile profile ON profile.user_id = account.id
                LEFT JOIN user_push_device device
                    ON device.user_id = account.id
                   AND device.enabled = TRUE
                WHERE (
                    LOWER(account.account_type) IN ('livreur', 'driver')
                    OR (
                        LOWER(account.account_type) = 'provider'
                        AND profile.can_deliver = TRUE
                        AND profile.validation_status = 'approved'
                    )
                  )
                SQL,
        );

        $targets = [];
        foreach ($rows as $row) {
            $userId = (int) $row['user_id'];
            $targets[$userId] ??= [];

            if (is_string($row['token'] ?? null) && $row['token'] !== '') {
                $targets[$userId][] = $row['token'];
            }
        }

        return $targets;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function persistNotification(string $sourceEventId, int $userId, array $payload): ?string
    {
        $notificationId = Uuid::v7()->toRfc4122();
        $inserted = $this->db->executeStatement(
            <<<'SQL'
                INSERT INTO user_notification (
                    id, user_id, source_event_id, type, title, body, data,
                    push_status, created_at
                )
                VALUES (
                    :id, :userId, :sourceEventId, :type, :title, :body, CAST(:data AS jsonb),
                    'PENDING', now()
                )
                ON CONFLICT (source_event_id, user_id) DO NOTHING
                SQL,
            [
                'id' => $notificationId,
                'userId' => $userId,
                'sourceEventId' => $sourceEventId,
                'type' => self::NOTIFICATION_TYPE,
                'title' => self::NOTIFICATION_TITLE,
                'body' => self::NOTIFICATION_BODY,
                'data' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ],
        );

        return $inserted === 1 ? $notificationId : null;
    }

    /**
     * @param list<string> $tokens
     * @param array<string, mixed> $payload
     * @return array{sent: int, failed: int, error: ?string}
     */
    private function sendPushNotifications(string $notificationId, array $tokens, array $payload): array
    {
        $sent = 0;
        $errors = [];
        $data = [
            'type' => self::NOTIFICATION_TYPE,
            'notificationId' => $notificationId,
            'deliveryId' => (string) ($payload['delivery']['id'] ?? ''),
            'status' => (string) ($payload['delivery']['status'] ?? ''),
        ];

        foreach ($tokens as $token) {
            try {
                $this->push->send(
                    (string) $token,
                    self::NOTIFICATION_TITLE,
                    self::NOTIFICATION_BODY,
                    $data,
                );
                ++$sent;
            } catch (\Throwable $exception) {
                $errors[] = $exception->getMessage();
                $this->logger->warning('Driver FCM new delivery notification failed', [
                    'deliveryId' => $payload['delivery']['id'] ?? null,
                    'exception' => $exception,
                ]);
            }
        }

        return [
            'sent' => $sent,
            'failed' => count($errors),
            'error' => $errors === [] ? null : mb_substr(implode(' | ', $errors), 0, 4000),
        ];
    }

    private function setPushResult(string $notificationId, string $status, int $attempts, ?string $error): void
    {
        $this->db->executeStatement(
            <<<'SQL'
                UPDATE user_notification
                SET push_status = :status,
                    push_attempts = :attempts,
                    push_last_error = :error
                WHERE id = :id
                SQL,
            [
                'id' => $notificationId,
                'status' => $status,
                'attempts' => $attempts,
                'error' => $error,
            ],
        );
    }
}
