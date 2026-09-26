<?php

declare(strict_types=1);

namespace App\Service\Tracking;

use Doctrine\DBAL\Connection;

final readonly class DriverAvailabilityService
{
    public function __construct(
        private Connection $db,
        private int $presenceTtlSeconds = 120,
        private int $maxLocationAccuracyMeters = 100,
    ) {
    }

    /** @return array{requestedOnline: bool, online: bool, effectiveOnline: bool, availabilityVersion: int, changedAt: ?string, lastLocationAt: ?string} */
    public function get(int $driverId): array
    {
        $row = $this->db->fetchAssociative(
            <<<'SQL'
                SELECT
                    availability.requested_online,
                    availability.availability_version,
                    availability.changed_at,
                    latest.recorded_at AS last_location_at,
                    availability.requested_online = TRUE
                        AND profile.validation_status = 'approved'
                        AND profile.can_deliver = TRUE
                        AND authorization.id IS NOT NULL
                        AND latest.recorded_at >= now() - (:presenceTtl * INTERVAL '1 second')
                        AND latest.accuracy <= :maxAccuracy
                        AND latest.is_mocked = FALSE
                        AND latest.is_suspect = FALSE AS effective_online
                FROM driver_availability availability
                JOIN provider_profile profile ON profile.user_id = availability.driver_id
                LEFT JOIN provider_authorization authorization
                  ON authorization.provider_profile_id = profile.id AND authorization.status = 'ACTIVE'
                LEFT JOIN LATERAL (
                    SELECT recorded_at, accuracy, is_mocked, is_suspect
                    FROM driver_location
                    WHERE driver_id = availability.driver_id
                    ORDER BY created_at DESC, id DESC
                    LIMIT 1
                ) latest ON TRUE
                WHERE availability.driver_id = :driverId
                SQL,
            [
                'driverId' => $driverId,
                'presenceTtl' => $this->presenceTtlSeconds,
                'maxAccuracy' => $this->maxLocationAccuracyMeters,
            ],
        );

        if ($row === false) {
            return [
                'requestedOnline' => false,
                'online' => false,
                'effectiveOnline' => false,
                'availabilityVersion' => 0,
                'changedAt' => null,
                'lastLocationAt' => null,
            ];
        }

        $requestedOnline = $this->toBool($row['requested_online']);

        return [
            'requestedOnline' => $requestedOnline,
            // Kept as a compatibility alias for existing mobile clients.
            'online' => $requestedOnline,
            'effectiveOnline' => $this->toBool($row['effective_online']),
            'availabilityVersion' => (int) $row['availability_version'],
            'changedAt' => $this->formatDate($row['changed_at']),
            'lastLocationAt' => $this->formatDate($row['last_location_at']),
        ];
    }

    /** @return array{requestedOnline: bool, online: bool, effectiveOnline: bool, availabilityVersion: int, changedAt: ?string, lastLocationAt: ?string} */
    public function set(int $driverId, bool $online, int $availabilityVersion): array
    {
        if ($availabilityVersion < 0) {
            throw new \InvalidArgumentException('availabilityVersion must be a non-negative integer.');
        }

        $current = $this->db->fetchAssociative(
            'SELECT requested_online, availability_version FROM driver_availability WHERE driver_id = :driverId',
            ['driverId' => $driverId],
        );
        if ($current !== false) {
            $currentVersion = (int) $current['availability_version'];
            $currentOnline = $this->toBool($current['requested_online']);
            if (
                $availabilityVersion < $currentVersion
                || ($availabilityVersion === $currentVersion && (!$currentOnline || $online))
            ) {
                return $this->get($driverId);
            }
        }

        if ($online && !$this->isEligibleDriver($driverId)) {
            throw new \DomainException('Le prestataire ne peut pas passer en ligne.');
        }

        $this->db->executeStatement(
            <<<'SQL'
                INSERT INTO driver_availability (driver_id, requested_online, availability_version, changed_at)
                VALUES (:driverId, :online, :availabilityVersion, now())
                ON CONFLICT (driver_id) DO UPDATE
                SET requested_online = EXCLUDED.requested_online,
                    availability_version = EXCLUDED.availability_version,
                    changed_at = CASE
                        WHEN driver_availability.requested_online IS DISTINCT FROM EXCLUDED.requested_online
                            THEN now()
                        ELSE driver_availability.changed_at
                    END
                WHERE EXCLUDED.availability_version > driver_availability.availability_version
                   OR (
                       EXCLUDED.availability_version = driver_availability.availability_version
                       AND EXCLUDED.requested_online = FALSE
                       AND driver_availability.requested_online = TRUE
                   )
                SQL,
            [
                'driverId' => $driverId,
                'online' => $online ? 'true' : 'false',
                'availabilityVersion' => $availabilityVersion,
            ],
        );

        return $this->get($driverId);
    }

    public function isRequestedOnline(int $driverId): bool
    {
        return $this->toBool($this->db->fetchOne(
            'SELECT requested_online FROM driver_availability WHERE driver_id = :driverId',
            ['driverId' => $driverId],
        ));
    }

    private function isEligibleDriver(int $driverId): bool
    {
        return $this->toBool($this->db->fetchOne(
            <<<'SQL'
                SELECT EXISTS (
                    SELECT 1
                    FROM provider_profile profile
                    JOIN provider_authorization authorization
                      ON authorization.provider_profile_id = profile.id
                     AND authorization.status = 'ACTIVE'
                    WHERE profile.user_id = :driverId
                      AND profile.can_deliver = TRUE
                      AND profile.validation_status = 'approved'
                )
                SQL,
            ['driverId' => $driverId],
        ));
    }

    private function toBool(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't';
    }

    private function formatDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (new \DateTimeImmutable((string) $value))->format(\DateTimeInterface::ATOM);
    }
}
