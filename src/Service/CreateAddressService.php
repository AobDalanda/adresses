<?php

namespace App\Service;

use App\Util\AddressQrCodec;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final class CreateAddressService
{
    public function __construct(private Connection $db)
    {
    }

    /**
     * @param array<int, array{lat: float, lng: float, accuracy?: float, source?: string}> $gpsPoints
     * @return array{addressId: int, identifier: string, displayLabel: string, countryCode: string, adminArea: array{id: int, name: string}|null, geoCellCode: string}
     */
    public function create(string $phone, array $gpsPoints, ?string $clientIp = null, ?string $providedCountryCode = null): array
    {
        if ($gpsPoints === []) {
            throw new \InvalidArgumentException('gpsPoints must not be empty.');
        }

        $this->db->beginTransaction();

        try {
            $gpsPointIds = [];

            foreach ($gpsPoints as $p) {
                $accuracy = isset($p['accuracy']) ? max(1.0, (float) $p['accuracy']) : null;
                $source = $p['source'] ?? 'mobile';

                $gpsPointIds[] = (int) $this->db->fetchOne(
                    "
                    INSERT INTO gps_raw_point (latitude, longitude, accuracy_m, source, geom)
                    VALUES (:lat, :lng, :acc, :source,
                        ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)::geography
                    )
                    RETURNING id
                    ",
                    [
                        'lat' => $p['lat'],
                        'lng' => $p['lng'],
                        'acc' => $accuracy,
                        'source' => $source,
                    ]
                );
            }

            $pointsUsed = count($gpsPointIds);

            $rawPoints = $this->db->fetchAllAssociative(
                "
                SELECT latitude, longitude, accuracy_m, source
                FROM gps_raw_point
                WHERE id = ANY(:ids)
                ",
                ['ids' => $gpsPointIds],
                ['ids' => ArrayParameterType::INTEGER]
            );

            [$lat, $lng, $confidenceScore, $weightedAccuracy] = $this->computeWeightedFromDb($rawPoints);

            $sources = array_values(array_unique(array_map(
                static fn (array $point): string => (string) ($point['source'] ?? 'unknown'),
                $rawPoints
            )));
            $weightedSource = count($sources) === 1 ? $sources[0] : 'mixed';

            $weightedId = (int) $this->db->fetchOne(
                "
                INSERT INTO gps_weighted_location
                    (final_geom, confidence_score, points_used, accuracy_m, source)
                VALUES (
                    ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)::geography,
                    :score,
                    :pts,
                    :accuracy,
                    :source
                )
                RETURNING id
                ",
                [
                    'lat' => $lat,
                    'lng' => $lng,
                    'score' => $confidenceScore,
                    'pts' => $pointsUsed,
                    'accuracy' => $weightedAccuracy,
                    'source' => $weightedSource,
                ]
            );

            foreach ($gpsPointIds as $gpsPointId) {
                $this->db->executeStatement(
                    'INSERT INTO gps_weighted_location_point (weighted_location_id, gps_raw_point_id) VALUES (:weightedId, :pointId)',
                    ['weightedId' => $weightedId, 'pointId' => $gpsPointId]
                );
            }

            $cellCode = $this->generateCellCode($lat, $lng);

            $cellId = (int) $this->db->fetchOne(
                "
                INSERT INTO geo_cell (cell_code, precision_m, centroid)
                VALUES (
                    :code,
                    3,
                    ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)::geography
                )
                ON CONFLICT (cell_code) DO UPDATE
                    SET cell_code = EXCLUDED.cell_code
                RETURNING id
                ",
                [
                    'code' => $cellCode,
                    'lat' => $lat,
                    'lng' => $lng,
                ]
            );

            $admin = $this->db->fetchAssociative(
                "
                SELECT id, name, country_code
                FROM geo_admin_area
                WHERE ST_Contains(
                    boundary,
                    ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)::geography
                )
                LIMIT 1
                ",
                [
                    'lat' => $lat,
                    'lng' => $lng,
                ]
            );

            $countryCode = $admin !== false
                ? strtoupper((string) $admin['country_code'])
                : ($providedCountryCode !== null ? strtoupper(trim($providedCountryCode)) : null);
            if ($countryCode === null || preg_match('/^[A-Z]{2}$/D', $countryCode) !== 1) {
                throw new \InvalidArgumentException(
                    'countryCode est requis lorsque la position ne correspond à aucune zone administrative connue'
                );
            }

            $addressCode = $this->buildAddressCode($admin !== false ? (int) $admin['id'] : null, $cellCode);
            $displayLabel = $this->buildDisplayLabel($phone, $admin !== false ? (string) $admin['name'] : $countryCode);

            $addressId = (int) $this->db->fetchOne(
                "
                INSERT INTO address
                    (address_code, display_label, phone_display, geo_cell_id,
                     weighted_location_id, admin_area_id, country_code)
                VALUES
                    (:code, :label, :phone, :cell, :weighted, :admin, :countryCode)
                RETURNING id
                ",
                [
                    'code' => $addressCode,
                    'label' => $displayLabel,
                    'phone' => $phone,
                    'cell' => $cellId,
                    'weighted' => $weightedId,
                    'admin' => $admin !== false ? $admin['id'] : null,
                    'countryCode' => $countryCode,
                ]
            );

            $this->db->executeStatement(
                "
                INSERT INTO audit_log
                    (actor, action, target, ip_address)
                VALUES
                    (:actor, 'CREATE_ADDRESS', :target, :ip)
                ",
                [
                    'actor' => $phone,
                    'target' => $addressCode,
                    'ip' => $clientIp,
                ]
            );

            $this->db->commit();

            return [
                'addressId' => $addressId,
                'identifier' => AddressQrCodec::encode($addressId),
                'displayLabel' => $displayLabel,
                'countryCode' => $countryCode,
                'adminArea' => $admin !== false ? [
                    'id' => (int) $admin['id'],
                    'name' => (string) $admin['name'],
                ] : null,
                'geoCellCode' => $cellCode,
            ];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * @param array<int, array{latitude: float, longitude: float, accuracy_m: ?float}> $points
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private function computeWeightedFromDb(array $points): array
    {
        $sumLat = 0.0;
        $sumLng = 0.0;
        $sumWeight = 0.0;

        foreach ($points as $p) {
            $accuracy = $p['accuracy_m'] !== null
                ? max(1.0, (float) $p['accuracy_m'])
                : 50.0;

            $weight = 1.0 / $accuracy;

            $sumLat += $p['latitude'] * $weight;
            $sumLng += $p['longitude'] * $weight;
            $sumWeight += $weight;
        }

        $lat = $sumLat / $sumWeight;
        $lng = $sumLng / $sumWeight;

        $avgAccuracy = array_sum(
            array_map(
                static fn ($p) => $p['accuracy_m'] ?? 50.0,
                $points
            )
        ) / count($points);

        $confidence = min(
            0.99,
            max(
                0.1,
                ($sumWeight / count($points)) * (1 / (1 + $avgAccuracy))
            )
        );

        return [$lat, $lng, $confidence, (float) $avgAccuracy];
    }

    private function generateCellCode(float $lat, float $lng): string
    {
        $cellSizeMeters = 3.0;
        $meters = $this->toWebMercatorMeters($lat, $lng);

        $x = (int) floor($meters['x'] / $cellSizeMeters);
        $y = (int) floor($meters['y'] / $cellSizeMeters);

        return sprintf(
            'GEO-3M-%s_%s',
            $this->encodeSignedInt($x),
            $this->encodeSignedInt($y)
        );
    }

    private function buildAddressCode(?int $adminId, string $cellCode): string
    {
        return $adminId !== null ? sprintf('ADM%d-%s', $adminId, $cellCode) : $cellCode;
    }

    private function buildDisplayLabel(string $phone, string $adminName): string
    {
        $cleanName = strtoupper(preg_replace('/\\s+/', '', $adminName));

        return sprintf('%s_%s', $phone, $cleanName);
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
