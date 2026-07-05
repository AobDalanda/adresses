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
    private const NOTIFICATION_ICON = 'ic_stat_delivery';
    private const NOTIFICATION_COLOR = '#0F766E';

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
                JOIN provider_profile profile ON profile.user_id = account.id
                JOIN provider_authorization provider_auth
                    ON provider_auth.provider_profile_id = profile.id
                   AND provider_auth.status = 'ACTIVE'
                LEFT JOIN user_push_device device
                    ON device.user_id = account.id
                   AND device.enabled = TRUE
                WHERE profile.can_deliver = TRUE
                  AND profile.validation_status = 'approved'
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

        foreach ($targets as $userId => $tokens) {
            $targets[$userId] = array_values(array_unique($tokens));
        }

        return $targets;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function persistNotification(string $sourceEventId, int $userId, array $payload): ?string
    {
        $notificationId = Uuid::v7()->toRfc4122();
        $content = $this->buildNotificationContent($payload);
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
                'title' => $content['title'],
                'body' => $content['body'],
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
        $content = $this->buildNotificationContent($payload);
        $data = [
            'type' => self::NOTIFICATION_TYPE,
            'notificationId' => $notificationId,
            'deliveryId' => (string) ($payload['delivery']['id'] ?? ''),
            'status' => (string) ($payload['delivery']['status'] ?? ''),
            'collapseKey' => 'delivery_order.' . (string) ($payload['delivery']['id'] ?? ''),
            'notificationGroup' => 'delivery_order',
            'notificationIcon' => self::NOTIFICATION_ICON,
            'notificationColor' => self::NOTIFICATION_COLOR,
        ];

        foreach ($tokens as $token) {
            try {
                $this->push->send(
                    (string) $token,
                    $content['title'],
                    $content['body'],
                    $data,
                );
                ++$sent;
            } catch (\Throwable $exception) {
                $errors[] = $exception->getMessage();
                if ($this->isPermanentPushTokenFailure($exception)) {
                    $this->disablePushDeviceToken((string) $token, $exception->getMessage());
                }

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

    /**
     * @param array<string, mixed> $payload
     * @return array{title: string, body: string}
     */
    private function buildNotificationContent(array $payload): array
    {
        $delivery = is_array($payload['delivery'] ?? null) ? $payload['delivery'] : [];
        $pricing = is_array($delivery['pricing'] ?? null) ? $delivery['pricing'] : [];

        $amount = $this->formatAmount($pricing['totalAmount'] ?? null, $pricing['currency'] ?? null);
        $distance = $this->formatDistance($pricing['distanceKm'] ?? null);
        $pickup = $this->addressLabel($delivery['pickupAddress'] ?? null, 'Départ');
        $dropoff = $this->addressLabel($delivery['dropoffAddress'] ?? null, 'Destination');

        $title = $amount === null
            ? self::NOTIFICATION_TITLE
            : sprintf('%s - %s', self::NOTIFICATION_TITLE, $amount);

        $parts = [];
        if ($pickup !== null || $dropoff !== null) {
            $parts[] = sprintf('%s -> %s', $pickup ?? 'Départ', $dropoff ?? 'Destination');
        }

        if ($distance !== null) {
            $parts[] = $distance;
        }

        return [
            'title' => $title,
            'body' => $parts === [] ? self::NOTIFICATION_BODY : implode(' · ', $parts),
        ];
    }

    private function formatAmount(mixed $amount, mixed $currency): ?string
    {
        if (!is_int($amount) && !is_float($amount) && !is_numeric($amount)) {
            return null;
        }

        $normalizedCurrency = is_string($currency) && $currency !== '' ? strtoupper($currency) : 'GNF';
        $formattedAmount = number_format((float) $amount, 0, ',', ' ');

        return sprintf('%s %s', $formattedAmount, $normalizedCurrency);
    }

    private function formatDistance(mixed $distanceKm): ?string
    {
        if (!is_int($distanceKm) && !is_float($distanceKm) && !is_numeric($distanceKm)) {
            return null;
        }

        return sprintf('%s km', number_format((float) $distanceKm, 1, ',', ' '));
    }

    private function addressLabel(mixed $address, string $fallback): ?string
    {
        if (!is_array($address)) {
            return null;
        }

        $label = $address['displayLabel'] ?? null;
        if (!is_string($label) || trim($label) === '') {
            return $fallback;
        }

        return mb_substr(trim($label), 0, 80);
    }

    private function isPermanentPushTokenFailure(\Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'requested entity was not found')
            || str_contains($message, 'token not found')
            || str_contains($message, 'registration token is not registered')
            || str_contains($message, 'registration-token-not-registered')
            || str_contains($message, 'unregistered');
    }

    private function disablePushDeviceToken(string $token, string $reason): void
    {
        $this->db->executeStatement(
            <<<'SQL'
                UPDATE user_push_device
                SET enabled = FALSE,
                    updated_at = now()
                WHERE token_hash = :tokenHash
                  AND enabled = TRUE
                SQL,
            ['tokenHash' => hash('sha256', $token)],
        );

        $this->logger->info('Disabled stale FCM push token after permanent failure', [
            'tokenHashPrefix' => substr(hash('sha256', $token), 0, 12),
            'reason' => mb_substr($reason, 0, 240),
        ]);
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
