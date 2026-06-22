<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final readonly class DeliveryOrderNotificationPublisher implements DeliveryOrderNotificationPublisherInterface
{
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

            $this->publishPushNotifications($payload);

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
    private function publishPushNotifications(array $payload): void
    {
        $tokens = $this->db->fetchFirstColumn(
            <<<'SQL'
                SELECT DISTINCT device.token
                FROM user_push_device device
                JOIN user_account account ON account.id = device.user_id
                LEFT JOIN provider_profile profile ON profile.user_id = account.id
                WHERE device.enabled = TRUE
                  AND (
                    LOWER(account.account_type) IN ('livreur', 'driver')
                    OR (
                        LOWER(account.account_type) = 'provider'
                        AND profile.can_deliver = TRUE
                        AND profile.validation_status = 'approved'
                    )
                  )
                SQL,
        );

        if ($tokens === []) {
            $this->logger->info('No driver FCM token registered for new delivery notification', [
                'deliveryId' => $payload['delivery']['id'] ?? null,
            ]);

            return;
        }

        $sent = 0;
        $errors = [];
        $data = [
            'type' => 'delivery_order.created',
            'deliveryId' => (string) ($payload['delivery']['id'] ?? ''),
            'status' => (string) ($payload['delivery']['status'] ?? ''),
        ];

        foreach ($tokens as $token) {
            try {
                $this->push->send(
                    (string) $token,
                    'Nouvelle livraison',
                    'Une nouvelle livraison est disponible.',
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

        $this->logger->info('Driver FCM new delivery notification completed', [
            'deliveryId' => $payload['delivery']['id'] ?? null,
            'targetedTokens' => count($tokens),
            'sent' => $sent,
            'failed' => count($errors),
        ]);
    }
}
