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
    public function transition(string $deliveryPublicId, int $driverId, string $targetStatus, ?string $comment = null, array $proof = []): array
    {
        if (!in_array($targetStatus, ['PICKED_UP', 'IN_TRANSIT', 'DELIVERED'], true)) {
            throw new \InvalidArgumentException('status est invalide');
        }

        return $this->db->transactional(function () use ($deliveryPublicId, $driverId, $targetStatus, $comment, $proof): array {
            $delivery = $this->db->fetchAssociative(
                <<<'SQL'
                    SELECT delivery.id, delivery.public_id, delivery.status, delivery.completed_at, package.signature_required
                    FROM delivery_order delivery
                    LEFT JOIN delivery_package package ON package.delivery_order_id = delivery.id
                    WHERE delivery.public_id = :publicId
                      AND delivery.assigned_driver_id = :driverId
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

            if ($targetStatus === 'DELIVERED') {
                $proof = $this->normalizeProof($proof);
                $this->assertDeliveryProof($proof, (bool) ($delivery['signature_required'] ?? false));
                $this->persistDeliveryProof((int) $delivery['id'], $driverId, $proof);
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

    /** @param array<string, mixed> $proof
     * @return array{receptionCode: ?string, recipientName: ?string, recipientSignatureAssetId: ?int, deliveryPhotoAssetId: ?int}
     */
    private function normalizeProof(array $proof): array
    {
        return [
            'receptionCode' => isset($proof['receptionCode']) && is_string($proof['receptionCode']) && trim($proof['receptionCode']) !== '' ? trim($proof['receptionCode']) : null,
            'recipientName' => isset($proof['recipientName']) && is_string($proof['recipientName']) && trim($proof['recipientName']) !== '' ? trim($proof['recipientName']) : null,
            'recipientSignatureAssetId' => isset($proof['recipientSignatureAssetId']) && is_int($proof['recipientSignatureAssetId']) && $proof['recipientSignatureAssetId'] > 0 ? $proof['recipientSignatureAssetId'] : null,
            'deliveryPhotoAssetId' => isset($proof['deliveryPhotoAssetId']) && is_int($proof['deliveryPhotoAssetId']) && $proof['deliveryPhotoAssetId'] > 0 ? $proof['deliveryPhotoAssetId'] : null,
        ];
    }

    /** @param array{receptionCode: ?string, recipientName: ?string, recipientSignatureAssetId: ?int, deliveryPhotoAssetId: ?int} $proof */
    private function assertDeliveryProof(array $proof, bool $signatureRequired): void
    {
        if ($proof['receptionCode'] === null) {
            throw new \InvalidArgumentException('receptionCode est requis');
        }
        if ($signatureRequired && $proof['recipientSignatureAssetId'] === null) {
            throw new \InvalidArgumentException('recipientSignatureAssetId est requis');
        }
        if ($proof['deliveryPhotoAssetId'] === null) {
            throw new \InvalidArgumentException('deliveryPhotoAssetId est requis');
        }
    }

    /** @param array<string, mixed> $proof */
    public function assertProofPayloadAllowed(string $targetStatus, array $proof): void
    {
        if ($targetStatus === 'DELIVERED') {
            return;
        }

        foreach (['receptionCode', 'recipientName'] as $field) {
            $value = $proof[$field] ?? null;
            if (is_string($value) && trim($value) !== '') {
                throw new \InvalidArgumentException('delivery proof fields are only allowed when status=DELIVERED');
            }
            if ($value !== null && !is_string($value)) {
                throw new \InvalidArgumentException('delivery proof fields are only allowed when status=DELIVERED');
            }
        }

        foreach (['recipientSignatureAssetId', 'deliveryPhotoAssetId'] as $field) {
            if (($proof[$field] ?? null) !== null) {
                throw new \InvalidArgumentException('delivery proof fields are only allowed when status=DELIVERED');
            }
        }
    }

    /** @param array{receptionCode: ?string, recipientName: ?string, recipientSignatureAssetId: ?int, deliveryPhotoAssetId: ?int} $proof */
    private function persistDeliveryProof(int $deliveryOrderId, int $driverId, array $proof): void
    {
        $signatureAssetId = $proof['recipientSignatureAssetId'];
        $photoAssetId = $proof['deliveryPhotoAssetId'];

        if ($signatureAssetId !== null) {
            $this->assertOwnedAsset($signatureAssetId, $driverId, 'recipient_signature');
            $this->consumeAsset($signatureAssetId, $driverId, 'recipient_signature');
        }
        if ($photoAssetId !== null) {
            $this->assertOwnedAsset($photoAssetId, $driverId, 'delivery_photo');
            $this->consumeAsset($photoAssetId, $driverId, 'delivery_photo');
        }

        $this->db->executeStatement(
            <<<'SQL'
                INSERT INTO delivery_proof (
                    delivery_order_id,
                    reception_code,
                    recipient_name,
                    recipient_signature_asset_id,
                    delivery_photo_asset_id,
                    signed_at,
                    photo_captured_at,
                    created_at,
                    updated_at
                )
                VALUES (
                    :deliveryOrderId,
                    :receptionCode,
                    :recipientName,
                    :signatureAssetId,
                    :photoAssetId,
                    CASE WHEN :signatureAssetId IS NOT NULL THEN now() ELSE NULL END,
                    CASE WHEN :photoAssetId IS NOT NULL THEN now() ELSE NULL END,
                    now(),
                    now()
                )
                ON CONFLICT (delivery_order_id) DO UPDATE
                SET reception_code = EXCLUDED.reception_code,
                    recipient_name = EXCLUDED.recipient_name,
                    recipient_signature_asset_id = EXCLUDED.recipient_signature_asset_id,
                    delivery_photo_asset_id = EXCLUDED.delivery_photo_asset_id,
                    signed_at = EXCLUDED.signed_at,
                    photo_captured_at = EXCLUDED.photo_captured_at,
                    updated_at = now()
                SQL,
            [
                'deliveryOrderId' => $deliveryOrderId,
                'receptionCode' => $proof['receptionCode'],
                'recipientName' => $proof['recipientName'],
                'signatureAssetId' => $signatureAssetId,
                'photoAssetId' => $photoAssetId,
            ],
        );
    }

    private function assertOwnedAsset(int $assetId, int $driverId, string $category): void
    {
        $asset = $this->db->fetchAssociative(
            <<<'SQL'
                SELECT asset.id
                FROM uploaded_asset asset
                JOIN upload_session session ON session.id = asset.session_id
                WHERE asset.id = :assetId
                  AND asset.category = :category
                  AND asset.validation_status = 'VALID'
                  AND asset.consumed_at IS NULL
                  AND session.user_id = :driverId
                LIMIT 1
                SQL,
            ['assetId' => $assetId, 'category' => $category, 'driverId' => $driverId],
        );

        if ($asset === false) {
            throw new \DomainException('DELIVERY_PROOF_ASSET_INVALID');
        }
    }

    private function consumeAsset(int $assetId, int $driverId, string $category): void
    {
        $updated = $this->db->executeStatement(
            <<<'SQL'
                UPDATE uploaded_asset asset
                SET consumed_at = now()
                FROM upload_session session
                WHERE asset.id = :assetId
                  AND asset.session_id = session.id
                  AND asset.category = :category
                  AND asset.validation_status = 'VALID'
                  AND asset.consumed_at IS NULL
                  AND session.user_id = :driverId
                SQL,
            ['assetId' => $assetId, 'category' => $category, 'driverId' => $driverId],
        );

        if ($updated < 1) {
            throw new \DomainException('DELIVERY_PROOF_ASSET_INVALID');
        }
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
