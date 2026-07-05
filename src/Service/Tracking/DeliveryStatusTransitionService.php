<?php

declare(strict_types=1);

namespace App\Service\Tracking;

use Doctrine\DBAL\Connection;

final readonly class DeliveryStatusTransitionService
{
    private const ALLOWED_TRANSITIONS = [
        'ASSIGNED' => 'PICKED_UP',
        'PICKED_UP' => 'IN_TRANSIT',
        'IN_TRANSIT' => 'DELIVERED',
    ];

    public function __construct(private Connection $db)
    {
    }

    /**
     * @return array{
     *     deliveryId: string,
     *     driverId: int,
     *     previousStatus: string,
     *     status: string,
     *     statusLabel: string,
     *     statusGroup: string,
     *     completedAt: ?string,
     *     nextTransition: ?array{status: string, label: string}
     * }
     */
    public function transition(string $deliveryPublicId, int $driverId, string $targetStatus, ?string $comment = null): array
    {
        if (!in_array($targetStatus, ['PICKED_UP', 'IN_TRANSIT', 'DELIVERED'], true)) {
            throw new \InvalidArgumentException('status est invalide');
        }

        return $this->db->transactional(function () use ($deliveryPublicId, $driverId, $targetStatus, $comment): array {
            $delivery = $this->db->fetchAssociative(
                <<<'SQL'
                    SELECT id, public_id, status, completed_at
                    FROM delivery_order
                    WHERE public_id = :publicId
                      AND assigned_driver_id = :driverId
                    LIMIT 1
                    SQL,
                ['publicId' => $deliveryPublicId, 'driverId' => $driverId],
            );

            if ($delivery === false) {
                throw new \OutOfBoundsException('MISSION_NOT_FOUND');
            }

            $currentStatus = (string) $delivery['status'];
            $expectedTarget = self::ALLOWED_TRANSITIONS[$currentStatus] ?? null;
            if ($expectedTarget === null) {
                throw new \DomainException('DELIVERY_STATUS_LOCKED');
            }
            if ($expectedTarget !== $targetStatus) {
                throw new \DomainException('INVALID_STATUS_TRANSITION');
            }

            $updated = $this->db->fetchAssociative(
                <<<'SQL'
                    UPDATE delivery_order
                    SET status = :status,
                        completed_at = CASE WHEN :status = 'DELIVERED' THEN now() ELSE completed_at END,
                        updated_at = now()
                    WHERE id = :id
                    RETURNING public_id, status, completed_at
                    SQL,
                ['status' => $targetStatus, 'id' => (int) $delivery['id']],
            );

            $historyComment = $comment;
            if ($historyComment === null || trim($historyComment) === '') {
                $historyComment = $this->defaultComment($targetStatus);
            }

            $this->db->executeStatement(
                <<<'SQL'
                    INSERT INTO delivery_status_history (
                        delivery_order_id, status, comment, changed_by_user_id,
                        changed_by_role, created_at
                    )
                    VALUES (
                        :deliveryId, :status, :comment, :driverId,
                        'DRIVER', now()
                    )
                    SQL,
                [
                    'deliveryId' => (int) $delivery['id'],
                    'status' => $targetStatus,
                    'comment' => $historyComment,
                    'driverId' => $driverId,
                ],
            );

            return [
                'deliveryId' => (string) $updated['public_id'],
                'driverId' => $driverId,
                'previousStatus' => $currentStatus,
                'status' => (string) $updated['status'],
                'statusLabel' => $this->statusLabel((string) $updated['status']),
                'statusGroup' => $this->statusGroup((string) $updated['status']),
                'completedAt' => $updated['completed_at'] !== null ? (string) $updated['completed_at'] : null,
                'nextTransition' => $this->nextTransition((string) $updated['status']),
            ];
        });
    }

    private function defaultComment(string $status): string
    {
        return match ($status) {
            'PICKED_UP' => 'Colis récupéré par le livreur.',
            'IN_TRANSIT' => 'Livraison en cours vers la destination.',
            'DELIVERED' => 'Livraison effectuée.',
            default => $status,
        };
    }

    private function statusLabel(string $status): string
    {
        return $status === 'DELIVERED' ? 'Terminée' : 'En cours';
    }

    private function statusGroup(string $status): string
    {
        return $status === 'DELIVERED' ? 'terminee' : 'en_cours';
    }

    /**
     * @return array{status: string, label: string}|null
     */
    private function nextTransition(string $status): ?array
    {
        $next = self::ALLOWED_TRANSITIONS[$status] ?? null;
        if ($next === null) {
            return null;
        }

        return [
            'status' => $next,
            'label' => match ($next) {
                'PICKED_UP' => 'Colis récupéré',
                'IN_TRANSIT' => 'Vers destination',
                'DELIVERED' => 'Livraison effectuée',
                default => $next,
            },
        ];
    }
}
