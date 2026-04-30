<?php

namespace App\Service;

use App\Util\AddressQrCodec;
use Doctrine\DBAL\Connection;

final class CreateConnectedUserAddressService
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
     *     label: string,
     *     latitude: float,
     *     longitude: float,
     *     description?: ?string,
     *     plus_code?: ?string,
     *     accuracy?: ?float,
     *     source?: ?string,
     *     isDefault?: ?bool
     * } $payload
     * @return array{
     *     addressId: int,
     *     identifier: string,
     *     addressCode: string,
     *     displayLabel: string,
     *     plusCode: string,
     *     adminArea: array{id: int, name: string}|null,
     *     verified: bool,
     *     verificationStatus: string,
     *     verificationWarning: ?string,
     *     isDefault: bool
     * }
     */
    public function createForUser(int $userId, string $phone, array $payload, ?string $clientIp = null): array
    {
        $label = trim($payload['label']);
        $latitude = $payload['latitude'];
        $longitude = $payload['longitude'];
        $description = isset($payload['description']) ? trim((string) $payload['description']) : null;
        $providedPlusCode = isset($payload['plus_code']) ? strtoupper(trim((string) $payload['plus_code'])) : null;
        $accuracy = isset($payload['accuracy']) ? (float) $payload['accuracy'] : null;
        $source = isset($payload['source']) && is_string($payload['source']) && trim($payload['source']) !== ''
            ? trim($payload['source'])
            : self::DEFAULT_SOURCE;
        $isDefault = (bool) ($payload['isDefault'] ?? true);

        $this->db->beginTransaction();

        try {
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
                $this->insertGpsOutlier($gpsPointId, sprintf('Accuracy élevée détectée: %.2fm', $accuracy));
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
                        sprintf('Décalage plus code / GPS: %.2fm', $distance)
                    );
                    $this->insertFraudEvent(
                        'USER_ADDRESS_CREATE',
                        $userId,
                        3,
                        sprintf('Le plus code fourni diffère de la position GPS de %.2fm', $distance)
                    );
                }
            }

            if ($adminArea === null) {
                $this->insertFraudEvent(
                    'USER_ADDRESS_CREATE',
                    $userId,
                    2,
                    'Aucune zone administrative trouvée pour cette position'
                );
            }

            $addressCode = $this->buildAddressCode($userId, $cellCode, $resolvedPlusCode);
            $address = $this->db->fetchAssociative(
                "
                INSERT INTO address (
                    address_code,
                    phone_display,
                    geo_cell_id,
                    plus_code_id,
                    weighted_location_id,
                    admin_area_id,
                    display_label
                )
                VALUES (
                    :addressCode,
                    :phoneDisplay,
                    :geoCellId,
                    :plusCodeId,
                    :weightedLocationId,
                    :adminAreaId,
                    :displayLabel
                )
                ON CONFLICT (phone_display, geo_cell_id) DO UPDATE
                    SET plus_code_id = EXCLUDED.plus_code_id,
                        weighted_location_id = EXCLUDED.weighted_location_id,
                        admin_area_id = EXCLUDED.admin_area_id,
                        display_label = EXCLUDED.display_label
                RETURNING id, address_code, display_label
                ",
                [
                    'addressCode' => $addressCode,
                    'phoneDisplay' => $phone,
                    'geoCellId' => $geoCellId,
                    'plusCodeId' => $plusCodeId,
                    'weightedLocationId' => $weightedLocationId,
                    'adminAreaId' => $adminArea['id'] ?? null,
                    'displayLabel' => $label,
                ]
            );

            $addressId = (int) $address['id'];

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
                    'reason' => $description !== null && $description !== ''
                        ? $description
                        : 'Création initiale',
                ]
            );

            if ($isDefault) {
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
                    'isPrimary' => $isDefault,
                ]
            );

            $this->db->executeStatement(
                "
                INSERT INTO audit_log (actor, action, target, ip_address)
                VALUES (:actor, 'CREATE_USER_ADDRESS', :target, :ip)
                ",
                [
                    'actor' => $phone,
                    'target' => $address['address_code'],
                    'ip' => $clientIp,
                ]
            );

            $this->db->commit();

            $verified = !$this->requireAdminArea || $adminArea !== null;
            $verificationStatus = $verified ? 'verified' : 'pending_admin_area';
            $verificationWarning = $adminArea === null
                ? 'Aucune zone administrative trouvée pour cette position'
                : null;

            return [
                'addressId' => $addressId,
                'identifier' => AddressQrCodec::encode($addressId),
                'addressCode' => (string) $address['address_code'],
                'displayLabel' => (string) $address['display_label'],
                'plusCode' => $resolvedPlusCode,
                'adminArea' => $adminArea !== null ? [
                    'id' => (int) $adminArea['id'],
                    'name' => (string) $adminArea['name'],
                ] : null,
                'verified' => $verified,
                'verificationStatus' => $verificationStatus,
                'verificationWarning' => $verificationWarning,
                'isDefault' => $isDefault,
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
