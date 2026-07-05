<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

final class DeliveryOverviewService
{
    private const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly Connection $db,
        private readonly UserAccountAssetUrlResolver $assetUrlResolver,
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function listForUser(int $userId, string $status = 'all', int $page = 1, int $perPage = 20): array
    {
        $status = $this->normalizeStatusFilter($status);
        $page = max(1, $page);
        $perPage = min(self::MAX_PER_PAGE, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        [$statusSql, $statusParams] = $this->buildStatusFilter($status);

        $items = $this->db->fetchAllAssociative(
            <<<SQL
                SELECT
                    delivery.id,
                    delivery.public_id,
                    delivery.status,
                    delivery.assigned_driver_id,
                    assigned_driver.name AS assigned_driver_name,
                    delivery.scheduled_at,
                    delivery.created_at,
                    delivery.recipient_name,
                    delivery.recipient_phone,
                    dropoff.id AS dropoff_address_id,
                    dropoff.display_label AS dropoff_display_label,
                    pickup.id AS pickup_address_id,
                    pickup.display_label AS pickup_display_label,
                    pricing.total_amount,
                    pricing.currency
                FROM delivery_order delivery
                LEFT JOIN address pickup ON pickup.id = delivery.pickup_address_id
                LEFT JOIN address dropoff ON dropoff.id = delivery.dropoff_address_id
                LEFT JOIN user_account assigned_driver ON assigned_driver.id = delivery.assigned_driver_id
                LEFT JOIN delivery_pricing_snapshot pricing ON pricing.delivery_order_id = delivery.id
                WHERE delivery.customer_id = :userId
                {$statusSql}
                ORDER BY COALESCE(delivery.scheduled_at, delivery.created_at) DESC, delivery.id DESC
                LIMIT :limit OFFSET :offset
                SQL,
            array_merge(
                [
                    'userId' => $userId,
                    'limit' => $perPage,
                    'offset' => $offset,
                ],
                $statusParams
            ),
            [
                'limit' => \PDO::PARAM_INT,
                'offset' => \PDO::PARAM_INT,
            ]
        );

        $totalItems = (int) $this->db->fetchOne(
            <<<SQL
                SELECT COUNT(*)
                FROM delivery_order delivery
                WHERE delivery.customer_id = :userId
                {$statusSql}
                SQL,
            array_merge(['userId' => $userId], $statusParams)
        );

        return [
            'items' => array_map(fn (array $row): array => $this->mapListItem($row), $items),
            'meta' => [
                'page' => $page,
                'perPage' => $perPage,
                'totalItems' => $totalItems,
                'totalPages' => max(1, (int) ceil($totalItems / $perPage)),
                'status' => $status,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detailForUser(int $userId, string $publicId): array
    {
        $row = $this->db->fetchAssociative(
            <<<SQL
                SELECT
                    delivery.id,
                    delivery.public_id,
                    delivery.status,
                    delivery.assigned_driver_id,
                    assigned_driver.name AS assigned_driver_name,
                    delivery.scheduled_at,
                    delivery.created_at,
                    delivery.notes,
                    delivery.recipient_name,
                    delivery.recipient_phone,
                    pickup.id AS pickup_address_id,
                    pickup.display_label AS pickup_display_label,
                    dropoff.id AS dropoff_address_id,
                    dropoff.display_label AS dropoff_display_label,
                    package.description AS package_description,
                    package.declared_value_amount,
                    package.declared_value_currency,
                    package.weight_kg,
                    package.length_cm,
                    package.width_cm,
                    package.height_cm,
                    package.fragile,
                    package.signature_required,
                    package.photo_asset_id,
                    pricing.distance_km,
                    pricing.duration_minutes,
                    pricing.base_amount,
                    pricing.surcharge_amount,
                    pricing.total_amount,
                    pricing.currency
                FROM delivery_order delivery
                LEFT JOIN address pickup ON pickup.id = delivery.pickup_address_id
                LEFT JOIN address dropoff ON dropoff.id = delivery.dropoff_address_id
                LEFT JOIN user_account assigned_driver ON assigned_driver.id = delivery.assigned_driver_id
                LEFT JOIN delivery_package package ON package.delivery_order_id = delivery.id
                LEFT JOIN delivery_pricing_snapshot pricing ON pricing.delivery_order_id = delivery.id
                WHERE delivery.customer_id = :userId
                  AND delivery.public_id = :publicId
                LIMIT 1
                SQL,
            [
                'userId' => $userId,
                'publicId' => $publicId,
            ]
        );

        if ($row === false) {
            throw new \RuntimeException('Commande introuvable.');
        }

        $history = $this->db->fetchAllAssociative(
            <<<SQL
                SELECT status, comment, created_at
                FROM delivery_status_history
                WHERE delivery_order_id = :deliveryOrderId
                ORDER BY created_at DESC, id DESC
                SQL,
            ['deliveryOrderId' => (int) $row['id']]
        );

        $item = $this->mapListItem($row);
        $item['notes'] = $row['notes'] !== null ? (string) $row['notes'] : null;
        $item['recipient'] = [
            'name' => $row['recipient_name'] !== null ? (string) $row['recipient_name'] : null,
            'phone' => $row['recipient_phone'] !== null ? (string) $row['recipient_phone'] : null,
        ];
        $item['package'] = [
            'description' => $row['package_description'] !== null ? (string) $row['package_description'] : null,
            'declaredValueAmount' => $row['declared_value_amount'] !== null ? (float) $row['declared_value_amount'] : null,
            'declaredValueCurrency' => $row['declared_value_currency'] !== null ? (string) $row['declared_value_currency'] : null,
            'weightKg' => $row['weight_kg'] !== null ? (float) $row['weight_kg'] : null,
            'lengthCm' => $row['length_cm'] !== null ? (float) $row['length_cm'] : null,
            'widthCm' => $row['width_cm'] !== null ? (float) $row['width_cm'] : null,
            'heightCm' => $row['height_cm'] !== null ? (float) $row['height_cm'] : null,
            'fragile' => (bool) ($row['fragile'] ?? false),
            'signatureRequired' => (bool) ($row['signature_required'] ?? false),
            'photoAssetId' => $row['photo_asset_id'] !== null ? (int) $row['photo_asset_id'] : null,
        ];
        $item['pricing'] = [
            'distanceKm' => $row['distance_km'] !== null ? (float) $row['distance_km'] : null,
            'durationMinutes' => $row['duration_minutes'] !== null ? (int) $row['duration_minutes'] : null,
            'baseAmount' => $row['base_amount'] !== null ? (float) $row['base_amount'] : null,
            'surchargeAmount' => $row['surcharge_amount'] !== null ? (float) $row['surcharge_amount'] : null,
            'totalAmount' => $row['total_amount'] !== null ? (float) $row['total_amount'] : null,
            'currency' => $row['currency'] !== null ? (string) $row['currency'] : null,
        ];
        $item['history'] = array_map(static fn (array $entry): array => [
            'status' => (string) $entry['status'],
            'label' => self::statusLabel((string) $entry['status']),
            'at' => (string) $entry['created_at'],
            'comment' => $entry['comment'] !== null ? (string) $entry['comment'] : null,
        ], $history);

        return $item;
    }

    private function normalizeStatusFilter(string $status): string
    {
        $normalized = strtolower(trim($status));

        return in_array($normalized, ['all', 'in_progress', 'pending', 'delivered'], true)
            ? $normalized
            : 'all';
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildStatusFilter(string $status): array
    {
        return match ($status) {
            'in_progress' => [" AND delivery.status IN (:statusConfirmed, :statusAssigned, :statusPickedUp, :statusInTransit)", [
                'statusConfirmed' => 'CONFIRMED',
                'statusAssigned' => 'ASSIGNED',
                'statusPickedUp' => 'PICKED_UP',
                'statusInTransit' => 'IN_TRANSIT',
            ]],
            'pending' => [" AND delivery.status IN (:statusDraft, :statusQuoted)", [
                'statusDraft' => 'DRAFT',
                'statusQuoted' => 'QUOTED',
            ]],
            'delivered' => [" AND delivery.status = :statusDelivered", [
                'statusDelivered' => 'DELIVERED',
            ]],
            default => ['', []],
        };
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapListItem(array $row): array
    {
        return [
            'id' => (string) $row['public_id'],
            'orderNumber' => sprintf('CMD-%03d', (int) $row['id']),
            'status' => [
                'code' => (string) $row['status'],
                'label' => self::statusLabel((string) $row['status']),
                'group' => self::statusGroup((string) $row['status']),
            ],
            'deliveryLabel' => $row['dropoff_display_label'] !== null ? (string) $row['dropoff_display_label'] : null,
            'scheduledAt' => $row['scheduled_at'] !== null ? (string) $row['scheduled_at'] : null,
            'createdAt' => (string) $row['created_at'],
            'displayDateTime' => (string) ($row['scheduled_at'] ?? $row['created_at']),
            'pickupAddress' => [
                'id' => $row['pickup_address_id'] !== null ? (int) $row['pickup_address_id'] : null,
                'displayLabel' => $row['pickup_display_label'] !== null ? (string) $row['pickup_display_label'] : null,
            ],
            'dropoffAddress' => [
                'id' => $row['dropoff_address_id'] !== null ? (int) $row['dropoff_address_id'] : null,
                'displayLabel' => $row['dropoff_display_label'] !== null ? (string) $row['dropoff_display_label'] : null,
            ],
            'driver' => $row['assigned_driver_id'] !== null ? [
                'id' => (int) $row['assigned_driver_id'],
                'name' => $row['assigned_driver_name'] !== null ? (string) $row['assigned_driver_name'] : null,
            ] : null,
            'tracking' => $row['assigned_driver_id'] !== null
                && in_array((string) $row['status'], ['ASSIGNED', 'PICKED_UP', 'IN_TRANSIT'], true)
                ? [
                    'stateUrl' => sprintf('/api/v1/deliveries/%s/tracking', (string) $row['public_id']),
                    'authorizationUrl' => sprintf(
                        '/api/v1/deliveries/%s/tracking-authorization',
                        (string) $row['public_id']
                    ),
                ]
                : null,
            'pricing' => [
                'totalAmount' => $row['total_amount'] !== null ? (float) $row['total_amount'] : null,
                'currency' => $row['currency'] !== null ? (string) $row['currency'] : null,
            ],
            'detailUrl' => sprintf('/api/v1/deliveries/%s', (string) $row['public_id']),
        ];
    }

    private static function statusLabel(string $status): string
    {
        return match ($status) {
            'DRAFT', 'QUOTED' => 'En attente',
            'CONFIRMED', 'ASSIGNED', 'PICKED_UP', 'IN_TRANSIT' => 'En cours',
            'DELIVERED' => 'Livrée',
            'CANCELLED' => 'Annulée',
            'FAILED' => 'Échouée',
            default => $status,
        };
    }

    private static function statusGroup(string $status): string
    {
        return match ($status) {
            'DRAFT', 'QUOTED' => 'pending',
            'CONFIRMED', 'ASSIGNED', 'PICKED_UP', 'IN_TRANSIT' => 'in_progress',
            'DELIVERED' => 'delivered',
            default => 'all',
        };
    }
}
