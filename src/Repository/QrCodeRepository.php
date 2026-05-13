<?php

namespace App\Repository;

use Doctrine\DBAL\Connection;

final class QrCodeRepository
{
    public function __construct(private Connection $db)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByToken(string $token, ?int $authenticatedUserId): ?array
    {
        return $this->db->fetchAssociative(
            "
            SELECT
                aq.id AS qr_id,
                aq.token,
                aq.is_active,
                aq.expires_at,
                aq.max_scans,
                aq.current_scans,
                aq.allowed_user_id,
                aq.created_by,
                aq.revoked_at,
                a.id AS address_id,
                COALESCE(a.display_label, a.address_code) AS name,
                av.reason AS description,
                ST_Y(gwl.final_geom::geometry) AS latitude,
                ST_X(gwl.final_geom::geometry) AS longitude
            FROM address_qrcodes aq
            JOIN address a ON a.id = aq.address_id
            LEFT JOIN gps_weighted_location gwl ON gwl.id = a.weighted_location_id
            LEFT JOIN LATERAL (
                SELECT reason
                FROM address_version av
                WHERE av.address_id = a.id
                ORDER BY av.versioned_at DESC, av.id DESC
                LIMIT 1
            ) av ON true
            WHERE aq.token = :token
            LIMIT 1
            ",
            [
                'token' => $token,
            ]
        ) ?: null;
    }

    public function incrementCurrentScans(int $qrCodeId): bool
    {
        return $this->db->executeStatement(
            "
            UPDATE address_qrcodes
            SET current_scans = current_scans + 1,
                updated_at = now()
            WHERE id = :qrCodeId
              AND (max_scans IS NULL OR current_scans < max_scans)
            ",
            ['qrCodeId' => $qrCodeId]
        ) === 1;
    }
}
