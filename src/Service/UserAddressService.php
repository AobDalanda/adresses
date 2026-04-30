<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

final class UserAddressService
{
    public function __construct(private Connection $db)
    {
    }

    /**
     * @return array{
     *     addressId: int,
     *     addressCode: string,
     *     displayLabel: ?string,
     *     phoneDisplay: ?string,
     *     latitude: ?float,
     *     longitude: ?float,
     *     plusCode: ?string,
     *     createdAt: string,
     *     isPrimary: bool
     * }|null
     */
    public function findUserAddress(int $userId): ?array
    {
        $row = $this->db->fetchAssociative(
            '
            SELECT
                a.id AS address_id,
                a.address_code,
                a.display_label,
                a.phone_display,
                ST_Y(gwl.final_geom::geometry) AS latitude,
                ST_X(gwl.final_geom::geometry) AS longitude,
                gpc.plus_code,
                a.created_at,
                ua.is_primary
            FROM user_address ua
            JOIN address a ON a.id = ua.address_id
            LEFT JOIN gps_weighted_location gwl ON gwl.id = a.weighted_location_id
            LEFT JOIN geo_plus_code gpc ON gpc.id = a.plus_code_id
            WHERE ua.user_id = :userId
            ORDER BY ua.is_primary DESC, ua.id DESC
            LIMIT 1
            ',
            ['userId' => $userId]
        );

        if ($row === false) {
            return null;
        }

        return [
            'addressId' => (int) $row['address_id'],
            'addressCode' => (string) $row['address_code'],
            'displayLabel' => $row['display_label'] !== null ? (string) $row['display_label'] : null,
            'phoneDisplay' => $row['phone_display'] !== null ? (string) $row['phone_display'] : null,
            'latitude' => $row['latitude'] !== null ? (float) $row['latitude'] : null,
            'longitude' => $row['longitude'] !== null ? (float) $row['longitude'] : null,
            'plusCode' => $row['plus_code'] !== null ? (string) $row['plus_code'] : null,
            'createdAt' => (string) $row['created_at'],
            'isPrimary' => (bool) $row['is_primary'],
        ];
    }

    public function attachAddressToUser(int $userId, int $addressId, bool $isPrimary = false): void
    {
        $this->db->beginTransaction();

        try {
            if ($isPrimary) {
                $this->db->executeStatement(
                    'UPDATE user_address SET is_primary = false WHERE user_id = :userId',
                    ['userId' => $userId]
                );
            }

            $this->db->executeStatement(
                "
                INSERT INTO user_address (user_id, address_id, is_primary)
                VALUES (:userId, :addressId, :isPrimary)
                ON CONFLICT (user_id, address_id) DO UPDATE
                    SET is_primary = EXCLUDED.is_primary
                ",
                [
                    'userId' => $userId,
                    'addressId' => $addressId,
                    'isPrimary' => $isPrimary,
                ]
            );

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
