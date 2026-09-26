<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Log\LoggerInterface;

final readonly class NearbyDriverMatcher
{
    public function __construct(
        private Connection $db,
        private LoggerInterface $logger,
        private int $presenceTtlSeconds = 120,
        private int $maxLocationAccuracyMeters = 100,
    ) {
    }

    /**
     * @return list<array{driverId: int, distanceMeters: int}>
     */
    public function findEligibleDrivers(
        float $pickupLatitude,
        float $pickupLongitude,
        string $serviceType,
        string $vehicleType,
        int $radiusMeters = 1000,
        ?string $pickupCountryCode = null,
        ?string $deliveryId = null,
    ): array {
        $diagnostics = $this->evaluateDrivers(
            $pickupLatitude,
            $pickupLongitude,
            $serviceType,
            $vehicleType,
            $radiusMeters,
            $pickupCountryCode,
        );

        foreach ($diagnostics as $diagnostic) {
            $context = ['deliveryId' => $deliveryId] + $diagnostic;
            $this->logger->info('Delivery driver matching evaluated', $context);
            if ($deliveryId !== null && $deliveryId !== '') {
                $this->persistDiagnostic($deliveryId, $diagnostic);
            }
        }

        return array_values(array_map(
            static fn (array $row): array => [
                'driverId' => $row['driverId'],
                'distanceMeters' => $row['distanceMeters'],
            ],
            array_filter($diagnostics, static fn (array $row): bool => $row['eligible']),
        ));
    }

    /**
     * @return list<array{driverId: int, online: bool, effectiveOnline: bool, locationAgeSeconds: ?int, accuracyMeters: ?float, distanceMeters: ?int, eligible: bool, rejectionReason: ?string}>
     */
    public function evaluateDrivers(
        float $pickupLatitude,
        float $pickupLongitude,
        string $serviceType,
        string $vehicleType,
        int $radiusMeters = 1000,
        ?string $pickupCountryCode = null,
    ): array {
        $this->assertCoordinates($pickupLatitude, $pickupLongitude);
        if ($radiusMeters < 1) {
            throw new \InvalidArgumentException('radiusMeters doit être positif.');
        }

        $rows = $this->db->fetchAllAssociative(
            <<<'SQL'
                SELECT
                    account.id AS driver_id,
                    COALESCE(availability.requested_online, FALSE) AS online,
                    COALESCE(availability.requested_online, FALSE)
                        AND profile.validation_status = 'approved'
                        AND provider_auth.id IS NOT NULL
                        AND latest_location.recorded_at >= now() - (:presenceTtl * INTERVAL '1 second')
                        AND latest_location.accuracy <= :maxAccuracy
                        AND latest_location.is_mocked = FALSE
                        AND latest_location.is_suspect = FALSE AS effective_online,
                    CASE WHEN latest_location.recorded_at IS NULL THEN NULL
                         ELSE GREATEST(0, EXTRACT(EPOCH FROM (now() - latest_location.recorded_at))::int)
                    END AS location_age_seconds,
                    latest_location.accuracy AS accuracy_meters,
                    CASE WHEN latest_location.position IS NULL THEN NULL ELSE ROUND(ST_Distance(
                        latest_location.position,
                        ST_SetSRID(ST_MakePoint(:pickupLongitude, :pickupLatitude), 4326)::geography
                    ))::int END AS distance_meters,
                    profile.can_deliver AS service_compatible,
                    EXISTS (
                        SELECT 1
                        FROM driver_application application
                        JOIN driver_vehicle vehicle ON vehicle.application_id = application.id
                        WHERE application.user_id = account.id
                          AND application.status = 'APPROVED'
                          AND vehicle.vehicle_type = :vehicleType
                    ) AS vehicle_compatible,
                    (CAST(:pickupCountry AS VARCHAR(2)) IS NULL OR EXISTS (
                        SELECT 1
                        FROM user_address ua
                        JOIN address driver_address ON driver_address.id = ua.address_id
                        WHERE ua.user_id = account.id
                          AND ua.is_primary = TRUE
                          AND driver_address.country_code = CAST(:pickupCountry AS VARCHAR(2))
                    ) OR (
                        NOT EXISTS (
                            SELECT 1
                            FROM user_address primary_address
                            WHERE primary_address.user_id = account.id
                              AND primary_address.is_primary = TRUE
                        )
                        AND latest_location.position IS NOT NULL
                        AND ST_DWithin(
                            latest_location.position,
                            ST_SetSRID(ST_MakePoint(:pickupLongitude, :pickupLatitude), 4326)::geography,
                            :radiusMeters
                        )
                    )) AS country_compatible,
                    profile.validation_status = 'approved' AND provider_auth.id IS NOT NULL AS account_validated
                FROM user_account account
                JOIN provider_profile profile ON profile.user_id = account.id
                LEFT JOIN provider_authorization provider_auth
                  ON provider_auth.provider_profile_id = profile.id
                 AND provider_auth.status = 'ACTIVE'
                LEFT JOIN driver_availability availability ON availability.driver_id = account.id
                LEFT JOIN LATERAL (
                    SELECT location.position, location.accuracy, location.is_mocked,
                           location.is_suspect, location.created_at, location.recorded_at
                    FROM driver_location location
                    WHERE location.driver_id = account.id
                    ORDER BY location.created_at DESC, location.id DESC
                    LIMIT 1
                ) latest_location ON TRUE
                WHERE profile.can_deliver = TRUE
                ORDER BY account.id
                SQL,
            [
                'presenceTtl' => $this->presenceTtlSeconds,
                'maxAccuracy' => $this->maxLocationAccuracyMeters,
                'pickupLongitude' => $pickupLongitude,
                'pickupLatitude' => $pickupLatitude,
                'pickupCountry' => $pickupCountryCode === null ? null : strtoupper($pickupCountryCode),
                'vehicleType' => strtoupper($vehicleType),
                'radiusMeters' => $radiusMeters,
            ],
            [
                'presenceTtl' => ParameterType::INTEGER,
                'maxAccuracy' => ParameterType::INTEGER,
                'pickupCountry' => ParameterType::STRING,
                'vehicleType' => ParameterType::STRING,
                'radiusMeters' => ParameterType::INTEGER,
            ],
        );

        return array_map(fn (array $row): array => $this->normalizeDiagnostic($row, $radiusMeters), $rows);
    }

    /** @param array<string, mixed> $row */
    private function normalizeDiagnostic(array $row, int $radiusMeters): array
    {
        $online = $this->toBool($row['online']);
        $effectiveOnline = $this->toBool($row['effective_online']);
        $distance = $row['distance_meters'] === null ? null : (int) $row['distance_meters'];
        $reason = match (true) {
            !$this->toBool($row['account_validated']) => 'ACCOUNT_NOT_VALIDATED',
            !$online => 'OFFLINE',
            $row['location_age_seconds'] === null => 'LOCATION_MISSING',
            (int) $row['location_age_seconds'] > $this->presenceTtlSeconds => 'LOCATION_STALE',
            (float) $row['accuracy_meters'] > $this->maxLocationAccuracyMeters => 'ACCURACY_TOO_LOW',
            !$effectiveOnline => 'LOCATION_NOT_TRUSTED',
            !$this->toBool($row['service_compatible']) => 'SERVICE_NOT_COMPATIBLE',
            !$this->toBool($row['vehicle_compatible']) => 'VEHICLE_NOT_COMPATIBLE',
            !$this->toBool($row['country_compatible']) => 'COUNTRY_NOT_COMPATIBLE',
            $distance === null => 'LOCATION_MISSING',
            $distance > $radiusMeters => 'OUTSIDE_RADIUS',
            default => null,
        };

        return [
            'driverId' => (int) $row['driver_id'],
            'online' => $online,
            'effectiveOnline' => $effectiveOnline,
            'locationAgeSeconds' => $row['location_age_seconds'] === null ? null : (int) $row['location_age_seconds'],
            'accuracyMeters' => $row['accuracy_meters'] === null ? null : (float) $row['accuracy_meters'],
            'distanceMeters' => $distance,
            'eligible' => $reason === null,
            'rejectionReason' => $reason,
        ];
    }

    /** @param array<string, mixed> $diagnostic */
    private function persistDiagnostic(string $deliveryId, array $diagnostic): void
    {
        $this->db->executeStatement(
            <<<'SQL'
                INSERT INTO delivery_matching_diagnostic (
                    delivery_id, driver_id, online, effective_online, location_age_seconds,
                    accuracy_meters, distance_meters, eligible, rejection_reason, evaluated_at
                ) VALUES (
                    :deliveryId, :driverId, :online, :effectiveOnline, :locationAgeSeconds,
                    :accuracyMeters, :distanceMeters, :eligible, :rejectionReason, now()
                )
                ON CONFLICT (delivery_id, driver_id) DO UPDATE SET
                    online = EXCLUDED.online,
                    effective_online = EXCLUDED.effective_online,
                    location_age_seconds = EXCLUDED.location_age_seconds,
                    accuracy_meters = EXCLUDED.accuracy_meters,
                    distance_meters = EXCLUDED.distance_meters,
                    eligible = EXCLUDED.eligible,
                    rejection_reason = EXCLUDED.rejection_reason,
                    evaluated_at = EXCLUDED.evaluated_at
                SQL,
            $diagnostic + ['deliveryId' => $deliveryId],
            [
                'deliveryId' => ParameterType::STRING,
                'driverId' => ParameterType::INTEGER,
                'online' => ParameterType::BOOLEAN,
                'effectiveOnline' => ParameterType::BOOLEAN,
                'locationAgeSeconds' => ParameterType::INTEGER,
                'distanceMeters' => ParameterType::INTEGER,
                'eligible' => ParameterType::BOOLEAN,
                'rejectionReason' => ParameterType::STRING,
            ],
        );
    }

    private function assertCoordinates(float $latitude, float $longitude): void
    {
        if (!is_finite($latitude) || $latitude < -90 || $latitude > 90) {
            throw new \InvalidArgumentException('Latitude invalide.');
        }
        if (!is_finite($longitude) || $longitude < -180 || $longitude > 180) {
            throw new \InvalidArgumentException('Longitude invalide.');
        }
    }

    private function toBool(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't';
    }
}
