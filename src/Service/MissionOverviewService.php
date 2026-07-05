<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;

final readonly class MissionOverviewService
{
    private const ELIGIBLE_STATUSES = "'ASSIGNED', 'PICKED_UP', 'IN_TRANSIT', 'DELIVERED'";

    public function __construct(private Connection $db)
    {
    }

    /**
     * @param array{status: string, page: int, perPage: int, sort: string, dateFrom: ?string, dateTo: ?string} $filters
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function listForDriver(int $driverId, array $filters): array
    {
        $now = (new \DateTimeImmutable())->format(\DATE_ATOM);
        $groupExpression = $this->groupExpression();
        $eventDateExpression = $this->eventDateExpression();
        $eligibleStatuses = self::ELIGIBLE_STATUSES;
        [$where, $params] = $this->filters($driverId, $filters, $now, $groupExpression, $eventDateExpression);
        $offset = ($filters['page'] - 1) * $filters['perPage'];

        $rows = $this->db->fetchAllAssociative(
            $this->baseSelect($groupExpression).<<<SQL
                {$where}
                {$this->orderBy($filters['sort'], $groupExpression)}
                LIMIT :limit OFFSET :offset
                SQL,
            array_merge($params, ['limit' => $filters['perPage'], 'offset' => $offset]),
            ['limit' => \PDO::PARAM_INT, 'offset' => \PDO::PARAM_INT]
        );

        $total = (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM delivery_order delivery {$where}",
            $params
        );

        $counts = $this->db->fetchAssociative(
            <<<SQL
                SELECT
                    COUNT(*) FILTER (WHERE {$groupExpression} = 'en_cours') AS en_cours,
                    COUNT(*) FILTER (WHERE {$groupExpression} = 'a_venir') AS a_venir,
                    COUNT(*) FILTER (WHERE {$groupExpression} = 'terminee') AS terminee
                FROM delivery_order delivery
                WHERE delivery.assigned_driver_id = :driverId
                  AND delivery.status IN ({$eligibleStatuses})
                SQL,
            ['driverId' => $driverId, 'now' => $now]
        ) ?: [];

        return [
            'data' => array_map(fn (array $row): array => $this->mapMission($row), $rows),
            'meta' => [
                'page' => $filters['page'],
                'perPage' => $filters['perPage'],
                'total' => $total,
                'totalPages' => $total === 0 ? 0 : (int) ceil($total / $filters['perPage']),
                'countsByStatus' => [
                    'en_cours' => (int) ($counts['en_cours'] ?? 0),
                    'a_venir' => (int) ($counts['a_venir'] ?? 0),
                    'terminee' => (int) ($counts['terminee'] ?? 0),
                ],
                'sort' => $filters['sort'],
                'filters' => array_filter([
                    'status' => $filters['status'],
                    'dateFrom' => $filters['dateFrom'],
                    'dateTo' => $filters['dateTo'],
                    'missionType' => 'livraison',
                ], static fn (mixed $value): bool => $value !== null),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function detailForDriver(int $driverId, string $publicId): array
    {
        $groupExpression = $this->groupExpression();
        $eligibleStatuses = self::ELIGIBLE_STATUSES;
        $row = $this->db->fetchAssociative(
            $this->baseSelect($groupExpression).<<<SQL
                WHERE delivery.assigned_driver_id = :driverId
                  AND delivery.public_id = :publicId
                  AND delivery.status IN ({$eligibleStatuses})
                LIMIT 1
                SQL,
            [
                'driverId' => $driverId,
                'publicId' => $publicId,
                'now' => (new \DateTimeImmutable())->format(\DATE_ATOM),
            ]
        );

        if ($row === false) {
            throw new \DomainException('MISSION_NOT_FOUND');
        }

        $mission = $this->mapMission($row);
        $mission['description'] = $row['notes'] !== null ? (string) $row['notes'] : null;
        $mission['contact'] = [
            'name' => $row['recipient_name'] !== null ? (string) $row['recipient_name'] : null,
            'phone' => $row['recipient_phone'] !== null ? (string) $row['recipient_phone'] : null,
        ];
        $mission['history'] = array_map(
            static fn (array $entry): array => [
                'status' => (string) $entry['status'],
                'at' => (string) $entry['created_at'],
                'comment' => $entry['comment'] !== null ? (string) $entry['comment'] : null,
            ],
            $this->db->fetchAllAssociative(
                'SELECT status, comment, created_at FROM delivery_status_history WHERE delivery_order_id = :id ORDER BY created_at ASC, id ASC',
                ['id' => (int) $row['internal_id']]
            )
        );

        return $mission;
    }

    private function baseSelect(string $groupExpression): string
    {
        return <<<SQL
            SELECT
                delivery.id AS internal_id,
                delivery.public_id,
                delivery.status AS delivery_status,
                delivery.scheduled_at,
                delivery.assigned_at,
                (
                    SELECT MIN(start_history.created_at)
                    FROM delivery_status_history start_history
                    WHERE start_history.delivery_order_id = delivery.id
                      AND start_history.status IN ('PICKED_UP', 'IN_TRANSIT')
                ) AS started_at,
                delivery.completed_at,
                delivery.created_at,
                delivery.notes,
                delivery.recipient_name,
                delivery.recipient_phone,
                delivery.service_type_code,
                service_type.name AS service_type_name,
                pickup.address_code AS pickup_address_code,
                pickup.display_label AS pickup_label,
                ST_Y(pickup_location.final_geom::geometry) AS pickup_latitude,
                ST_X(pickup_location.final_geom::geometry) AS pickup_longitude,
                pickup_location.accuracy_m AS pickup_accuracy_m,
                pickup_location.source AS pickup_gps_source,
                dropoff.address_code AS dropoff_address_code,
                dropoff.display_label AS dropoff_label,
                ST_Y(dropoff_location.final_geom::geometry) AS dropoff_latitude,
                ST_X(dropoff_location.final_geom::geometry) AS dropoff_longitude,
                dropoff_location.accuracy_m AS dropoff_accuracy_m,
                dropoff_location.source AS dropoff_gps_source,
                pricing.distance_km,
                pricing.duration_minutes,
                earning.estimated_amount,
                earning.final_amount,
                earning.currency AS earning_currency,
                {$groupExpression} AS mission_status
            FROM delivery_order delivery
            JOIN address pickup ON pickup.id = delivery.pickup_address_id
            JOIN address dropoff ON dropoff.id = delivery.dropoff_address_id
            LEFT JOIN gps_weighted_location pickup_location ON pickup_location.id = pickup.weighted_location_id
            LEFT JOIN gps_weighted_location dropoff_location ON dropoff_location.id = dropoff.weighted_location_id
            LEFT JOIN service_types service_type ON service_type.code = delivery.service_type_code
            LEFT JOIN delivery_pricing_snapshot pricing ON pricing.delivery_order_id = delivery.id
            LEFT JOIN delivery_driver_earning earning ON earning.delivery_order_id = delivery.id
            SQL;
    }

    /**
     * @param array{status: string, page: int, perPage: int, sort: string, dateFrom: ?string, dateTo: ?string} $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function filters(
        int $driverId,
        array $filters,
        string $now,
        string $groupExpression,
        string $eventDateExpression
    ): array {
        $where = 'WHERE delivery.assigned_driver_id = :driverId AND delivery.status IN ('.self::ELIGIBLE_STATUSES.') AND CAST(:now AS timestamptz) IS NOT NULL';
        $params = ['driverId' => $driverId, 'now' => $now];

        if ($filters['status'] !== 'all') {
            $where .= " AND {$groupExpression} = :missionStatus";
            $params['missionStatus'] = $filters['status'];
        }
        if ($filters['dateFrom'] !== null) {
            $where .= " AND {$eventDateExpression} >= :dateFrom";
            $params['dateFrom'] = $filters['dateFrom'];
        }
        if ($filters['dateTo'] !== null) {
            $where .= " AND {$eventDateExpression} <= :dateTo";
            $params['dateTo'] = $filters['dateTo'];
        }

        return [$where, $params];
    }

    private function groupExpression(): string
    {
        return <<<'SQL'
            CASE
                WHEN delivery.status = 'DELIVERED' THEN 'terminee'
                WHEN delivery.status = 'ASSIGNED' AND delivery.scheduled_at > CAST(:now AS timestamptz) THEN 'a_venir'
                ELSE 'en_cours'
            END
            SQL;
    }

    private function eventDateExpression(): string
    {
        return <<<'SQL'
            CASE
                WHEN delivery.status = 'DELIVERED' THEN COALESCE(delivery.completed_at, delivery.updated_at)
                WHEN delivery.status = 'ASSIGNED' AND delivery.scheduled_at > CAST(:now AS timestamptz) THEN delivery.scheduled_at
                ELSE COALESCE(delivery.assigned_at, delivery.scheduled_at, delivery.created_at)
            END
            SQL;
    }

    private function orderBy(string $sort, string $groupExpression): string
    {
        return match ($sort) {
            'scheduled_at' => 'ORDER BY delivery.scheduled_at ASC NULLS LAST, delivery.id ASC',
            '-scheduled_at' => 'ORDER BY delivery.scheduled_at DESC NULLS LAST, delivery.id DESC',
            'completed_at' => 'ORDER BY delivery.completed_at ASC NULLS LAST, delivery.id ASC',
            '-completed_at' => 'ORDER BY delivery.completed_at DESC NULLS LAST, delivery.id DESC',
            'started_at' => 'ORDER BY started_at ASC NULLS LAST, delivery.id ASC',
            '-started_at' => 'ORDER BY started_at DESC NULLS LAST, delivery.id DESC',
            default => <<<SQL
                ORDER BY
                    CASE {$groupExpression}
                        WHEN 'en_cours' THEN 1
                        WHEN 'a_venir' THEN 2
                        ELSE 3
                    END,
                    CASE WHEN {$groupExpression} = 'a_venir' THEN delivery.scheduled_at END ASC NULLS LAST,
                    COALESCE(delivery.completed_at, delivery.assigned_at, delivery.scheduled_at, delivery.created_at) DESC,
                    delivery.id DESC
                SQL,
        };
    }

    /** @param array<string, mixed> $row */
    private function mapMission(array $row): array
    {
        $status = (string) $row['mission_status'];
        $finalAmount = $row['final_amount'] !== null ? (string) $row['final_amount'] : null;
        $estimatedAmount = $row['estimated_amount'] !== null ? (string) $row['estimated_amount'] : null;
        $amount = $finalAmount ?? $estimatedAmount;
        $primaryAction = $status === 'en_cours'
            ? ['code' => 'continue_mission', 'label' => 'Continuer la mission']
            : ($status === 'a_venir' ? ['code' => 'view_detail', 'label' => 'Voir détail'] : null);

        return [
            'id' => (string) $row['public_id'],
            'reference' => sprintf('MIS-%06d', (int) $row['internal_id']),
            'status' => $status,
            'statusLabel' => match ($status) {
                'en_cours' => 'En cours',
                'a_venir' => 'À venir',
                default => 'Terminée',
            },
            'deliveryStatus' => (string) $row['delivery_status'],
            'title' => match ($status) {
                'en_cours' => 'Mission en cours',
                'a_venir' => 'Mission à venir',
                default => 'Mission terminée',
            },
            'missionType' => 'livraison',
            'missionTypeLabel' => 'Livraison',
            'serviceLevel' => [
                'code' => (string) $row['service_type_code'],
                'label' => $row['service_type_name'] !== null ? (string) $row['service_type_name'] : null,
            ],
            'scheduledAt' => $row['scheduled_at'] !== null ? (string) $row['scheduled_at'] : null,
            'assignedAt' => $row['assigned_at'] !== null ? (string) $row['assigned_at'] : null,
            'startedAt' => $row['started_at'] !== null ? (string) $row['started_at'] : null,
            'completedAt' => $row['completed_at'] !== null ? (string) $row['completed_at'] : null,
            'pickup' => $this->mapAddress($row, 'pickup'),
            'dropoff' => $this->mapAddress($row, 'dropoff'),
            'route' => [
                'distanceKm' => $row['distance_km'] !== null ? (float) $row['distance_km'] : null,
                'estimatedDurationMinutes' => $row['duration_minutes'] !== null ? (int) $row['duration_minutes'] : null,
            ],
            'earning' => [
                'amount' => $amount,
                'estimatedAmount' => $estimatedAmount,
                'finalAmount' => $finalAmount,
                'currency' => $row['earning_currency'] !== null ? (string) $row['earning_currency'] : null,
                'isEstimated' => $amount !== null && $finalAmount === null,
            ],
            'actions' => [
                'primary' => $primaryAction !== null ? array_merge($primaryAction, [
                    'enabled' => true,
                    'method' => 'GET',
                    'href' => sprintf('/api/v1/missions/%s', (string) $row['public_id']),
                ]) : null,
            ],
        ];
    }

    /** @param array<string, mixed> $row */
    private function mapAddress(array $row, string $prefix): array
    {
        return [
            'addressCode' => (string) $row[$prefix.'_address_code'],
            'label' => $row[$prefix.'_label'] !== null ? (string) $row[$prefix.'_label'] : null,
            'latitude' => $row[$prefix.'_latitude'] !== null ? (float) $row[$prefix.'_latitude'] : null,
            'longitude' => $row[$prefix.'_longitude'] !== null ? (float) $row[$prefix.'_longitude'] : null,
            'gpsPrecisionM' => $row[$prefix.'_accuracy_m'] !== null ? (float) $row[$prefix.'_accuracy_m'] : null,
            'gpsSource' => $row[$prefix.'_gps_source'] !== null ? (string) $row[$prefix.'_gps_source'] : null,
        ];
    }
}
