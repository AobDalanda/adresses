<?php

declare(strict_types=1);

namespace App\Service\Tracking;

use App\Exception\DriverNotAuthorizedException;
use App\Exception\DriverProfileIncompleteException;
use App\Exception\DriverProfileNotFoundException;
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
                    profile.id AS profile_id,
                    profile.can_deliver,
                    profile.validation_status,
                    provider_auth.id AS authorization_id,
                    provider_auth.status AS authorization_status,
                    provider_auth.can_deliver AS authorization_can_deliver,
                    legacy_application.status AS legacy_application_status,
                    COALESCE(availability.requested_online, FALSE) AS requested_online,
                    COALESCE(availability.availability_version, 0) AS availability_version,
                    availability.changed_at,
                    latest.recorded_at AS last_location_at,
                    COALESCE(availability.requested_online, FALSE) = TRUE
                        AND profile.validation_status = 'approved'
                        AND profile.can_deliver = TRUE
                        AND provider_auth.status = 'ACTIVE'
                        AND provider_auth.can_deliver = TRUE
                        AND latest.recorded_at >= now() - (:presenceTtl * INTERVAL '1 second')
                        AND latest.accuracy <= :maxAccuracy
                        AND latest.is_mocked = FALSE
                        AND latest.is_suspect = FALSE AS effective_online
                FROM user_account account
                LEFT JOIN provider_profile profile ON profile.user_id = account.id
                LEFT JOIN provider_authorization provider_auth
                  ON provider_auth.provider_profile_id = profile.id
                LEFT JOIN driver_application legacy_application
                  ON legacy_application.user_id = account.id
                 AND legacy_application.status = 'APPROVED'
                LEFT JOIN driver_availability availability ON availability.driver_id = account.id
                LEFT JOIN LATERAL (
                    SELECT recorded_at, accuracy, is_mocked, is_suspect
                    FROM driver_location
                    WHERE driver_id = account.id
                    ORDER BY created_at DESC, id DESC
                    LIMIT 1
                ) latest ON TRUE
                WHERE account.id = :driverId
                SQL,
            [
                'driverId' => $driverId,
                'presenceTtl' => $this->presenceTtlSeconds,
                'maxAccuracy' => $this->maxLocationAccuracyMeters,
            ],
        );

        if ($row === false) {
            throw new DriverProfileNotFoundException('Utilisateur introuvable.');
        }

        $this->assertProfileExists($row);

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
    public function set(int $driverId, bool $online): array
    {
        $profile = $this->profileState($driverId);
        if ($online) {
            $this->assertProfileIsUsable($profile);
        }

        $this->db->executeStatement(
            <<<'SQL'
                INSERT INTO driver_availability (driver_id, requested_online, availability_version, changed_at)
                VALUES (:driverId, :online, 1, now())
                ON CONFLICT (driver_id) DO UPDATE
                SET requested_online = EXCLUDED.requested_online,
                    availability_version = driver_availability.availability_version + 1,
                    changed_at = CASE
                        WHEN driver_availability.requested_online IS DISTINCT FROM EXCLUDED.requested_online
                            THEN now()
                        ELSE driver_availability.changed_at
                    END
                SQL,
            [
                'driverId' => $driverId,
                'online' => $online ? 'true' : 'false',
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

    /** @return array<string, mixed> */
    private function profileState(int $driverId): array
    {
        $row = $this->db->fetchAssociative(
            <<<'SQL'
                SELECT
                    profile.id AS profile_id,
                    profile.can_deliver,
                    profile.validation_status,
                    provider_auth.id AS authorization_id,
                    provider_auth.status AS authorization_status,
                    provider_auth.can_deliver AS authorization_can_deliver,
                    legacy_application.status AS legacy_application_status
                FROM user_account account
                LEFT JOIN provider_profile profile ON profile.user_id = account.id
                LEFT JOIN provider_authorization provider_auth
                  ON provider_auth.provider_profile_id = profile.id
                LEFT JOIN driver_application legacy_application
                  ON legacy_application.user_id = account.id
                 AND legacy_application.status = 'APPROVED'
                WHERE account.id = :driverId
                SQL,
            ['driverId' => $driverId],
        );

        if ($row === false) {
            throw new DriverProfileNotFoundException('Utilisateur introuvable.');
        }

        if ($row['profile_id'] === null) {
            if ($row['legacy_application_status'] === 'APPROVED') {
                throw new DriverProfileNotFoundException('Aucun profil Driver canonique n’existe pour ce prestataire approuvé.');
            }

            throw new DriverNotAuthorizedException('Ce compte n’est pas un prestataire.');
        }

        return $row;
    }

    /** @param array<string, mixed> $row */
    private function assertProfileIsUsable(array $row): void
    {
        $this->assertProfileExists($row);
        if (!$this->toBool($row['can_deliver']) || (string) $row['validation_status'] !== 'approved') {
            throw new DriverNotAuthorizedException('Le profil prestataire n’est pas autorisé à livrer.');
        }
        if ($row['authorization_id'] === null) {
            throw new DriverProfileIncompleteException('Le profil Driver ne possède aucune autorisation exploitable.');
        }
        if (
            (string) $row['authorization_status'] !== 'ACTIVE'
            || !$this->toBool($row['authorization_can_deliver'])
        ) {
            throw new DriverNotAuthorizedException('L’autorisation de livraison du prestataire n’est pas active.');
        }
    }

    /** @param array<string, mixed> $row */
    private function assertProfileExists(array $row): void
    {
        if ($row['profile_id'] === null) {
            if (($row['legacy_application_status'] ?? null) === 'APPROVED') {
                throw new DriverProfileNotFoundException('Aucun profil Driver canonique n’existe pour ce prestataire approuvé.');
            }

            throw new DriverNotAuthorizedException('Ce compte n’est pas un prestataire.');
        }
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
