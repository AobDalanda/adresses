<?php

declare(strict_types=1);

namespace App\Service\Tracking;

use Doctrine\DBAL\Connection;

final readonly class DeliveryAssignmentService
{
    public function __construct(private Connection $db)
    {
    }

    /** @return array{deliveryId: string, driverId: int, status: string, assignedAt: string} */
    public function accept(string $deliveryPublicId, int $driverId): array
    {
        return $this->db->transactional(function () use ($deliveryPublicId, $driverId): array {
            $delivery = $this->db->fetchAssociative(
                <<<'SQL'
                    UPDATE delivery_order
                    SET assigned_driver_id = :driverId,
                        assigned_at = now(),
                        status = 'ASSIGNED',
                        updated_at = now()
                    WHERE public_id = :publicId
                      AND status = 'CONFIRMED'
                      AND assigned_driver_id IS NULL
                    RETURNING id, public_id, status, assigned_at
                    SQL,
                ['driverId' => $driverId, 'publicId' => $deliveryPublicId],
            );
            if ($delivery === false) {
                throw new \DomainException('DELIVERY_NOT_AVAILABLE');
            }

            $this->db->executeStatement(
                <<<'SQL'
                    INSERT INTO delivery_status_history (
                        delivery_order_id, status, comment, changed_by_user_id,
                        changed_by_role, created_at
                    )
                    VALUES (
                        :deliveryId, 'ASSIGNED', :comment, :driverId,
                        'DRIVER', now()
                    )
                    SQL,
                [
                    'deliveryId' => (int) $delivery['id'],
                    'comment' => 'Commande acceptée par le livreur.',
                    'driverId' => $driverId,
                ],
            );

            return [
                'deliveryId' => (string) $delivery['public_id'],
                'driverId' => $driverId,
                'status' => (string) $delivery['status'],
                'assignedAt' => (string) $delivery['assigned_at'],
            ];
        });
    }
}
