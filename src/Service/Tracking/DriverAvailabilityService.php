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

    /** @return array{online: bool, effectiveOnline: bool, changedAt: ?string, lastHeartbeatAt: ?string} */
    public function get(int $driverId): array
    {
        $row = $this->db->fetchAssociative(
            <<<'SQL'
                SELECT
                    is_online,
                    changed_at,
                    last_heartbeat_at,
                    is_online = TRUE
                        AND last_heartbeat_at >= now() - (:presenceTtl * INTERVAL '1 second') AS effective_online
                FROM driver_availability
                WHERE driver_id = :driverId
                SQL,
            ['driverId' => $driverId, 'presenceTtl' => $this->presenceTtlSeconds],
        );

        if ($row === false) {
            return ['online' => false, 'effectiveOnline' => false, 'changedAt' => null, 'lastHeartbeatAt' => null];
        }

        return [
            'online' => $this->toBool($row['is_online']),
            'effectiveOnline' => $this->toBool($row['effective_online']),
            'changedAt' => $this->formatDate($row['changed_at']),
            'lastHeartbeatAt' => $this->formatDate($row['last_heartbeat_at']),
        ];
    }

    /** @return array{online: bool, effectiveOnline: bool, changedAt: ?string, lastHeartbeatAt: ?string} */
    public function set(int $driverId, bool $online): array
    {
        if ($online && !$this->isEligibleDriver($driverId)) {
            throw new \DomainException('Le prestataire ne peut pas passer en ligne.');
        }

        $this->db->executeStatement(
            <<<'SQL'
                INSERT INTO driver_availability (driver_id, is_online, changed_at, last_heartbeat_at)
                VALUES (:driverId, :online, now(), NULL)
                ON CONFLICT (driver_id) DO UPDATE
                SET is_online = EXCLUDED.is_online,
                    changed_at = CASE
                        WHEN driver_availability.is_online IS DISTINCT FROM EXCLUDED.is_online THEN now()
                        ELSE driver_availability.changed_at
                    END,
                    last_heartbeat_at = CASE
                        WHEN EXCLUDED.is_online = FALSE THEN NULL
                        ELSE driver_availability.last_heartbeat_at
                    END
                SQL,
            ['driverId' => $driverId, 'online' => $online ? 'true' : 'false'],
        );

        if ($online) {
            $this->activateHeartbeatFromRecentLocation($driverId);
        }

        return $this->get($driverId);
    }

    public function heartbeat(int $driverId): void
    {
        $this->db->executeStatement(
            <<<'SQL'
                UPDATE driver_availability
                SET last_heartbeat_at = now()
                WHERE driver_id = :driverId
                  AND is_online = TRUE
                SQL,
            ['driverId' => $driverId],
        );
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

    private function activateHeartbeatFromRecentLocation(int $driverId): void
    {
        $this->db->executeStatement(
            <<<'SQL'
                UPDATE driver_availability availability
                SET last_heartbeat_at = now()
                WHERE availability.driver_id = :driverId
                  AND EXISTS (
                      SELECT 1
                      FROM LATERAL (
                          SELECT created_at, accuracy, is_mocked, is_suspect
                          FROM driver_location
                          WHERE driver_id = :driverId
                          ORDER BY created_at DESC, id DESC
                          LIMIT 1
                      ) latest
                      WHERE latest.created_at >= now() - (:presenceTtl * INTERVAL '1 second')
                        AND latest.accuracy <= :maxAccuracy
                        AND latest.is_mocked = FALSE
                        AND latest.is_suspect = FALSE
                  )
                SQL,
            [
                'driverId' => $driverId,
                'presenceTtl' => $this->presenceTtlSeconds,
                'maxAccuracy' => $this->maxLocationAccuracyMeters,
            ],
        );
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
