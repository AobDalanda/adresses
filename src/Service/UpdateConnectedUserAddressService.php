<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

final class UpdateConnectedUserAddressService
{
    private const DEFAULT_SOURCE = 'mobile';
    private const GPS_OUTLIER_ACCURACY_METERS = 100.0;
    private const PLUS_CODE_MISMATCH_METERS = 150.0;

    public function __construct(
        private Connection $db,
        private PlusCodeService $plusCodes,
        private bool $requireAdminArea = false
    ) {
    }

    /**
     * @param array{
     *     latitude: float,
     *     longitude: float,
     *     plus_code?: ?string,
     *     accuracy?: ?float,
     *     source?: ?string,
     *     reason?: ?string
     * } $payload
     * @return array{
     *     addressId: int,
     *     addressCode: string,
     *     displayLabel: ?string,
     *     plusCode: string,
     *     latitude: float,
     *     longitude: float,
     *     adminArea: array{id: int, name: string}|null,
     *     verified: bool,
     *     verificationStatus: string,
     *     verificationWarning: ?string,
     *     isPrimary: bool
     * }
     */
    public function updateCurrentUserAddress(int $userId, string $phone, array $payload, ?string $clientIp = null): array
    {
        $latitude = $payload['latitude'];
        $longitude = $payload['longitude'];
        $providedPlusCode = isset($payload['plus_code']) ? strtoupper(trim((string) $payload['plus_code'])) : null;
        $accuracy = isset($payload['accuracy']) ? (float) $payload['accuracy'] : null;
        $source = isset($payload['source']) && is_string($payload['source']) && trim($payload['source']) !== ''
            ? trim($payload['source'])
            : self::DEFAULT_SOURCE;
        $reason = isset($payload['reason']) && is_string($payload['reason']) && trim($payload['reason']) !== ''
            ? trim($payload['reason'])
            : 'Mise à jour de la localisation';

        $this->db->beginTransaction();

        try {
            $currentAddress = $this->db->fetchAssociative(
                '
                SELECT
                    a.id AS address_id,
                    a.display_label,
                    ua.is_primary
                FROM user_address ua
                JOIN address a ON a.id = ua.address_id
                WHERE ua.user_id = :userId
                ORDER BY ua.is_primary DESC, ua.id DESC
                LIMIT 1
                ',
                ['userId' => $userId]
            );

            if ($currentAddress === false) {
                throw new \InvalidArgumentException('Aucune adresse trouvée pour cet utilisateur');
            }

            $addressId = (int) $currentAddress['address_id'];

            $gpsPointId = (int) $this->db->fetchOne(
                "
                INSERT INTO gps_raw_point (latitude, longitude, accuracy_m, source, geom)
                VALUES (
                    :lat,
                    :lng,
                    :accuracy,
                    :source,
                    ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)::geography
                )
                RETURNING id
                ",
                [
                    'lat' => $latitude,
                    'lng' => $longitude,
                    'accuracy' => $accuracy,
                    'source' => $source,
                ]
            );

            if ($accuracy !== null && $accuracy > self::GPS_OUTLIER_ACCURACY_METERS) {
                $this->insertGpsOutlier($gpsPointId, sprintf('Accuracy elevee detectee: %.2fm', $accuracy));
            }

            $weightedLocationId = (int) $this->db->fetchOne(
                "
                INSERT INTO gps_weighted_location (final_geom, confidence_score, points_used)
                VALUES (
                    ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)::geography,
                    :confidence,
                    1
                )
                RETURNING id
                ",
                [
                    'lat' => $latitude,
                    'lng' => $longitude,
                    'confidence' => $this->computeConfidenceScore($accuracy),
                ]
            );

            $cellCode = $this->generateCellCode($latitude, $longitude);
            $geoCellId = (int) $this->db->fetchOne(
                "
                INSERT INTO geo_cell (cell_code, precision_m, centroid)
                VALUES (
                    :code,
                    3,
                    ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)::geography
                )
                ON CONFLICT (cell_code) DO UPDATE
                    SET centroid = EXCLUDED.centroid
                RETURNING id
                ",
                [
                    'code' => $cellCode,
                    'lat' => $latitude,
                    'lng' => $longitude,
                ]
            );

            $adminArea = $this->db->fetchAssociative(
                "
                SELECT id, name
                FROM geo_admin_area
                WHERE boundary IS NOT NULL
                  AND ST_Contains(
                    boundary::geometry,
                    ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)
                )
                LIMIT 1
                ",
                [
                    'lat' => $latitude,
                    'lng' => $longitude,
                ]
            ) ?: null;

            $resolvedPlusCode = $this->resolvePlusCode($providedPlusCode, $latitude, $longitude);
            $plusCodeId = (int) $this->db->fetchOne(
                "
                INSERT INTO geo_plus_code (plus_code, precision_level, location)
                VALUES (
                    :plusCode,
                    10,
                    ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)::geography
                )
                ON CONFLICT (plus_code) DO UPDATE
                    SET location = EXCLUDED.location
                RETURNING id
                ",
                [
                    'plusCode' => $resolvedPlusCode,
                    'lat' => $latitude,
                    'lng' => $longitude,
                ]
            );

            if ($providedPlusCode !== null) {
                $distance = $this->computePlusCodeDistanceMeters($resolvedPlusCode, $latitude, $longitude);
                if ($distance > self::PLUS_CODE_MISMATCH_METERS) {
                    $this->insertGpsOutlier(
                        $gpsPointId,
                        sprintf('Decalage plus code / GPS: %.2fm', $distance)
                    );
                    $this->insertFraudEvent(
                        'USER_ADDRESS_UPDATE',
                        $addressId,
                        3,
                        sprintf('Le plus code fourni differe de la position GPS de %.2fm', $distance)
                    );
                }
            }

            if ($adminArea === null) {
                $this->insertFraudEvent(
                    'USER_ADDRESS_UPDATE',
                    $addressId,
                    2,
                    'Aucune zone administrative trouvee pour cette position'
                );
            }

            $addressCode = $this->buildAddressCode($userId, $cellCode, $resolvedPlusCode);
            $this->db->executeStatement(
                "
                UPDATE address
                SET address_code = :addressCode,
                    geo_cell_id = :geoCellId,
                    plus_code_id = :plusCodeId,
                    weighted_location_id = :weightedLocationId,
                    admin_area_id = :adminAreaId
                WHERE id = :addressId
                ",
                [
                    'addressCode' => $addressCode,
                    'geoCellId' => $geoCellId,
                    'plusCodeId' => $plusCodeId,
                    'weightedLocationId' => $weightedLocationId,
                    'adminAreaId' => $adminArea['id'] ?? null,
                    'addressId' => $addressId,
                ]
            );

            $this->db->executeStatement(
                "
                INSERT INTO address_version (address_id, location, reason)
                VALUES (
                    :addressId,
                    ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)::geography,
                    :reason
                )
                ",
                [
                    'addressId' => $addressId,
                    'lat' => $latitude,
                    'lng' => $longitude,
                    'reason' => $reason,
                ]
            );

            $this->db->executeStatement(
                "
                INSERT INTO audit_log (actor, action, target, ip_address)
                VALUES (:actor, 'UPDATE_USER_ADDRESS_LOCATION', :target, :ip)
                ",
                [
                    'actor' => $phone,
                    'target' => $addressCode,
                    'ip' => $clientIp,
                ]
            );

            $this->db->commit();

            $verified = !$this->requireAdminArea || $adminArea !== null;
            $verificationStatus = $verified ? 'verified' : 'pending_admin_area';
            $verificationWarning = $adminArea === null
                ? 'Aucune zone administrative trouvee pour cette position'
                : null;

            return [
                'addressId' => $addressId,
                'addressCode' => $addressCode,
                'displayLabel' => $currentAddress['display_label'] !== null ? (string) $currentAddress['display_label'] : null,
                'plusCode' => $resolvedPlusCode,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'adminArea' => $adminArea !== null ? [
                    'id' => (int) $adminArea['id'],
                    'name' => (string) $adminArea['name'],
                ] : null,
                'verified' => $verified,
                'verificationStatus' => $verificationStatus,
                'verificationWarning' => $verificationWarning,
                'isPrimary' => (bool) $currentAddress['is_primary'],
            ];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function resolvePlusCode(?string $providedPlusCode, float $latitude, float $longitude): string
    {
        if ($providedPlusCode === null || $providedPlusCode === '') {
            return $this->plusCodes->encode($latitude, $longitude, 10);
        }

        if (!$this->plusCodes->isValid($providedPlusCode)) {
            throw new \InvalidArgumentException('plus_code est invalide');
        }

        if ($this->plusCodes->isShort($providedPlusCode)) {
            return strtoupper($this->plusCodes->recoverNearest($providedPlusCode, $latitude, $longitude));
        }

        return strtoupper($providedPlusCode);
    }

    private function computeConfidenceScore(?float $accuracy): float
    {
        if ($accuracy === null) {
            return 0.85;
        }

        return min(0.99, max(0.10, 1 / (1 + ($accuracy / 10))));
    }

    private function insertGpsOutlier(int $gpsPointId, string $reason): void
    {
        $this->db->executeStatement(
            '
            INSERT INTO gps_outlier (gps_point_id, reason)
            VALUES (:gpsPointId, :reason)
            ',
            [
                'gpsPointId' => $gpsPointId,
                'reason' => $reason,
            ]
        );
    }

    private function insertFraudEvent(string $entityType, int $entityId, int $riskLevel, string $description): void
    {
        $this->db->executeStatement(
            '
            INSERT INTO fraud_event (entity_type, entity_id, risk_level, description)
            VALUES (:entityType, :entityId, :riskLevel, :description)
            ',
            [
                'entityType' => $entityType,
                'entityId' => $entityId,
                'riskLevel' => $riskLevel,
                'description' => $description,
            ]
        );
    }

    private function computePlusCodeDistanceMeters(string $plusCode, float $latitude, float $longitude): float
    {
        $area = $this->plusCodes->decode($plusCode);

        return $this->distanceMeters(
            $latitude,
            $longitude,
            $area->latitudeCenter,
            $area->longitudeCenter
        );
    }

    private function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $earthRadius * asin(min(1.0, sqrt($a)));
    }

    private function generateCellCode(float $lat, float $lng): string
    {
        $cellSizeMeters = 3.0;
        $meters = $this->toWebMercatorMeters($lat, $lng);

        $x = (int) floor($meters['x'] / $cellSizeMeters);
        $y = (int) floor($meters['y'] / $cellSizeMeters);

        return sprintf(
            'GN-3M-%s_%s',
            $this->encodeSignedInt($x),
            $this->encodeSignedInt($y)
        );
    }

    private function buildAddressCode(int $userId, string $cellCode, string $plusCode): string
    {
        return sprintf(
            'U%d-%s-%s',
            $userId,
            substr(hash('crc32b', $cellCode), 0, 6),
            substr(str_replace('+', '', $plusCode), 0, 8)
        );
    }

    /**
     * @return array{x: float, y: float}
     */
    private function toWebMercatorMeters(float $lat, float $lng): array
    {
        $earthRadius = 6378137.0;
        $latRad = deg2rad(max(-85.05112878, min(85.05112878, $lat)));
        $lngRad = deg2rad($lng);

        return [
            'x' => $earthRadius * $lngRad,
            'y' => $earthRadius * log(tan(M_PI / 4.0 + $latRad / 2.0)),
        ];
    }

    private function encodeSignedInt(int $value): string
    {
        $sign = $value < 0 ? 'N' : 'P';

        return $sign . base_convert((string) abs($value), 10, 36);
    }
}
