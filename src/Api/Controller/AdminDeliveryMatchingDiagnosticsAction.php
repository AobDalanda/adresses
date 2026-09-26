<?php

declare(strict_types=1);

namespace App\Api\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;

final readonly class AdminDeliveryMatchingDiagnosticsAction
{
    public function __construct(private Security $security, private Connection $db)
    {
    }

    public function __invoke(string $deliveryId): JsonResponse
    {
        if (!$this->security->isGranted('ROLE_ADMIN')) {
            return new JsonResponse(['message' => 'Forbidden'], 403);
        }

        $deliveryExists = $this->db->fetchOne(
            'SELECT EXISTS(SELECT 1 FROM delivery_order WHERE public_id = :deliveryId)',
            ['deliveryId' => $deliveryId],
        );
        if (!in_array($deliveryExists, [true, 1, '1', 't'], true)) {
            return new JsonResponse(['message' => 'Livraison introuvable'], 404);
        }

        $rows = $this->db->fetchAllAssociative(
            <<<'SQL'
                SELECT driver_id, online, effective_online, location_age_seconds,
                       accuracy_meters, distance_meters, eligible, rejection_reason, evaluated_at
                FROM delivery_matching_diagnostic
                WHERE delivery_id = :deliveryId
                ORDER BY eligible DESC, distance_meters ASC NULLS LAST, driver_id
                SQL,
            ['deliveryId' => $deliveryId],
        );

        return new JsonResponse([
            'deliveryId' => $deliveryId,
            'drivers' => array_map(fn (array $row): array => [
                'driverId' => (int) $row['driver_id'],
                'online' => $this->bool($row['online']),
                'effectiveOnline' => $this->bool($row['effective_online']),
                'locationAgeSeconds' => $row['location_age_seconds'] === null ? null : (int) $row['location_age_seconds'],
                'accuracyMeters' => $row['accuracy_meters'] === null ? null : (float) $row['accuracy_meters'],
                'distanceMeters' => $row['distance_meters'] === null ? null : (int) $row['distance_meters'],
                'eligible' => $this->bool($row['eligible']),
                'rejectionReason' => $row['rejection_reason'],
                'evaluatedAt' => $row['evaluated_at'],
            ], $rows),
        ]);
    }

    private function bool(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't'], true);
    }
}
