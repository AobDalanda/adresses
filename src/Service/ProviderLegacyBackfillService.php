<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

class ProviderLegacyBackfillService
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @return array{
     *     dryRun: bool,
     *     candidates: int,
     *     imported: int,
     *     applications: int,
     *     authorizations: int,
     *     remaining: int,
     *     skipped: array<string, int>
     * }
     */
    public function backfill(int $batchSize = 100, bool $dryRun = false): array
    {
        if ($batchSize < 1 || $batchSize > 1000) {
            throw new \InvalidArgumentException('La taille de lot doit etre comprise entre 1 et 1000.');
        }

        $skipped = $this->loadSkippedCounts();
        $candidates = $this->loadCandidates($batchSize);

        if ($dryRun) {
            return [
                'dryRun' => true,
                'candidates' => count($candidates),
                'imported' => 0,
                'applications' => count($candidates),
                'authorizations' => count($candidates),
                'remaining' => $this->countEligibleProfiles(),
                'skipped' => $skipped,
            ];
        }

        $imported = 0;
        foreach ($candidates as $candidate) {
            $created = $this->db->transactional(
                fn (): bool => $this->importCandidate($candidate)
            );
            if ($created) {
                ++$imported;
            }
        }

        return [
            'dryRun' => false,
            'candidates' => count($candidates),
            'imported' => $imported,
            'applications' => $imported,
            'authorizations' => $imported,
            'remaining' => $this->countEligibleProfiles(),
            'skipped' => $skipped,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadCandidates(int $batchSize): array
    {
        return $this->db->fetchAllAssociative(
            <<<'SQL'
                SELECT
                    profile.id AS profile_id,
                    profile.user_id,
                    profile.can_deliver,
                    profile.can_transport_people,
                    profile.validation_status AS profile_status,
                    profile.created_at AS profile_created_at,
                    profile.updated_at AS profile_updated_at,
                    account.phone,
                    account.name,
                    account.email,
                    account.identity_document_path,
                    account.identity_document_number,
                    account.driver_license_path,
                    application.id AS legacy_application_id,
                    application.signup_as,
                    application.status AS application_status,
                    application.submitted_at,
                    application.created_at AS application_created_at,
                    application.updated_at AS application_updated_at,
                    (
                        SELECT to_jsonb(vehicle)
                        FROM driver_vehicle vehicle
                        WHERE vehicle.application_id = application.id
                    ) AS vehicle,
                    (
                        SELECT to_jsonb(license)
                        FROM driver_license license
                        WHERE license.application_id = application.id
                    ) AS driver_license,
                    (
                        SELECT COALESCE(jsonb_agg(to_jsonb(document) ORDER BY document.id), '[]'::jsonb)
                        FROM driver_vehicle_document document
                        WHERE document.application_id = application.id
                    ) AS vehicle_documents,
                    (
                        SELECT COALESCE(jsonb_agg(to_jsonb(photo) ORDER BY photo.sort_order, photo.id), '[]'::jsonb)
                        FROM driver_vehicle_photo photo
                        WHERE photo.application_id = application.id
                    ) AS vehicle_photos,
                    (
                        SELECT COALESCE(jsonb_agg(to_jsonb(zone) ORDER BY zone.id), '[]'::jsonb)
                        FROM driver_delivery_zone zone
                        WHERE zone.application_id = application.id
                    ) AS delivery_zones
                FROM provider_profile profile
                JOIN user_account account ON account.id = profile.user_id
                LEFT JOIN driver_application application ON application.user_id = profile.user_id
                WHERE account.account_type = 'provider'
                  AND NOT EXISTS (
                      SELECT 1
                      FROM provider_application canonical
                      WHERE canonical.provider_profile_id = profile.id
                  )
                  AND (
                      application.id IS NULL
                      OR (
                          (SELECT COUNT(*) FROM driver_application candidate WHERE candidate.user_id = profile.user_id) = 1
                          AND lower(application.status) = profile.validation_status
                          AND profile.can_deliver = (application.signup_as IN ('LIVREUR', 'BOTH'))
                          AND profile.can_transport_people = (application.signup_as IN ('TRANSPORTEUR', 'BOTH'))
                      )
                  )
                ORDER BY profile.id
                LIMIT :limit
                SQL,
            ['limit' => $batchSize],
            ['limit' => ParameterType::INTEGER],
        );
    }

    /**
     * @param array<string, mixed> $candidate
     */
    private function importCandidate(array $candidate): bool
    {
        $profileId = (int) $candidate['profile_id'];
        $locked = $this->db->fetchOne(
            <<<'SQL'
                SELECT profile.id
                FROM provider_profile profile
                WHERE profile.id = :profileId
                  AND NOT EXISTS (
                      SELECT 1
                      FROM provider_application application
                      WHERE application.provider_profile_id = profile.id
                  )
                FOR UPDATE
                SQL,
            ['profileId' => $profileId],
        );

        if ($locked === false) {
            return false;
        }

        $applicationStatus = $candidate['application_status'] === null
            && strtolower((string) $candidate['profile_status']) === 'pending'
                ? 'DRAFT'
                : $this->canonicalApplicationStatus(
                    $candidate['application_status'] !== null
                        ? (string) $candidate['application_status']
                        : (string) $candidate['profile_status'],
                );
        $authorizationStatus = $this->canonicalAuthorizationStatus((string) $candidate['profile_status']);
        $activities = $this->activities($candidate);
        $profileData = $this->profileData($candidate);
        $createdAt = (string) ($candidate['application_created_at'] ?? $candidate['profile_created_at']);
        $updatedAt = (string) ($candidate['application_updated_at'] ?? $candidate['profile_updated_at']);
        $submittedAt = $candidate['submitted_at'] ?? (
            $applicationStatus === 'DRAFT' ? null : $createdAt
        );
        $decidedAt = in_array($applicationStatus, ['APPROVED', 'REJECTED'], true) ? $updatedAt : null;
        $publicId = Uuid::v7()->toRfc4122();

        $applicationId = (int) $this->db->fetchOne(
            <<<'SQL'
                INSERT INTO provider_application (
                    public_id,
                    provider_profile_id,
                    status,
                    legacy_driver_application_id,
                    submitted_at,
                    decided_at,
                    created_at,
                    updated_at
                )
                VALUES (
                    :publicId,
                    :profileId,
                    :status,
                    :legacyApplicationId,
                    :submittedAt,
                    :decidedAt,
                    :createdAt,
                    :updatedAt
                )
                RETURNING id
                SQL,
            [
                'publicId' => $publicId,
                'profileId' => $profileId,
                'status' => $applicationStatus,
                'legacyApplicationId' => $candidate['legacy_application_id'] !== null
                    ? (int) $candidate['legacy_application_id']
                    : null,
                'submittedAt' => $submittedAt,
                'decidedAt' => $decidedAt,
                'createdAt' => $createdAt,
                'updatedAt' => $updatedAt,
            ],
        );

        $revisionId = (int) $this->db->fetchOne(
            <<<'SQL'
                INSERT INTO provider_application_revision (
                    application_id,
                    version,
                    activities,
                    profile_data,
                    submitted_at,
                    created_by,
                    created_at
                )
                VALUES (
                    :applicationId,
                    1,
                    CAST(:activities AS jsonb),
                    CAST(:profileData AS jsonb),
                    :submittedAt,
                    :createdBy,
                    :createdAt
                )
                RETURNING id
                SQL,
            [
                'applicationId' => $applicationId,
                'activities' => $this->encodeJson($activities),
                'profileData' => $this->encodeJson($profileData),
                'submittedAt' => $submittedAt,
                'createdBy' => (int) $candidate['user_id'],
                'createdAt' => $createdAt,
            ],
        );

        $this->db->executeStatement(
            <<<'SQL'
                UPDATE provider_application
                SET current_revision_id = :revisionId,
                    approved_revision_id = CASE WHEN status = 'APPROVED' THEN :revisionId ELSE NULL END
                WHERE id = :applicationId
                SQL,
            ['applicationId' => $applicationId, 'revisionId' => $revisionId],
        );

        $this->db->executeStatement(
            <<<'SQL'
                INSERT INTO provider_authorization (
                    provider_profile_id,
                    source_application_id,
                    source_revision_id,
                    status,
                    can_deliver,
                    can_transport_people,
                    suspension_reason_code,
                    suspended_at,
                    created_at,
                    updated_at
                )
                VALUES (
                    :profileId,
                    :applicationId,
                    :revisionId,
                    :status,
                    :canDeliver,
                    :canTransportPeople,
                    :suspensionReasonCode,
                    :suspendedAt,
                    :createdAt,
                    :updatedAt
                )
                ON CONFLICT (provider_profile_id) DO NOTHING
                SQL,
            [
                'profileId' => $profileId,
                'applicationId' => $applicationId,
                'revisionId' => $revisionId,
                'status' => $authorizationStatus,
                'canDeliver' => $authorizationStatus === 'INACTIVE' ? false : $this->toBoolean($candidate['can_deliver']),
                'canTransportPeople' => $authorizationStatus === 'INACTIVE'
                    ? false
                    : $this->toBoolean($candidate['can_transport_people']),
                'suspensionReasonCode' => $authorizationStatus === 'SUSPENDED' ? 'LEGACY_SUSPENSION' : null,
                'suspendedAt' => $authorizationStatus === 'SUSPENDED' ? $updatedAt : null,
                'createdAt' => (string) $candidate['profile_created_at'],
                'updatedAt' => (string) $candidate['profile_updated_at'],
            ],
            [
                'canDeliver' => ParameterType::BOOLEAN,
                'canTransportPeople' => ParameterType::BOOLEAN,
            ],
        );

        $this->db->executeStatement(
            <<<'SQL'
                INSERT INTO provider_decision_history (
                    application_id,
                    revision_id,
                    transition,
                    old_status,
                    new_status,
                    actor_type,
                    actor_id,
                    reason_code,
                    metadata,
                    occurred_at,
                    correlation_id
                )
                VALUES (
                    :applicationId,
                    :revisionId,
                    'IMPORT_LEGACY',
                    NULL,
                    :newStatus,
                    'SYSTEM',
                    NULL,
                    'LEGACY_BACKFILL',
                    CAST(:metadata AS jsonb),
                    :occurredAt,
                    :correlationId
                )
                SQL,
            [
                'applicationId' => $applicationId,
                'revisionId' => $revisionId,
                'newStatus' => $applicationStatus,
                'metadata' => $this->encodeJson([
                    'legacyDriverApplicationId' => $candidate['legacy_application_id'] !== null
                        ? (int) $candidate['legacy_application_id']
                        : null,
                    'legacyProfileStatus' => (string) $candidate['profile_status'],
                ]),
                'occurredAt' => $updatedAt,
                'correlationId' => Uuid::v7()->toRfc4122(),
            ],
        );

        return true;
    }

    /**
     * @return array<string, int>
     */
    private function loadSkippedCounts(): array
    {
        $row = $this->db->fetchAssociative(
            <<<'SQL'
                SELECT
                    COUNT(DISTINCT id) FILTER (WHERE account_type <> 'provider') AS account_type_mismatch,
                    COUNT(DISTINCT id) FILTER (
                        WHERE application_count > 1
                    ) AS multiple_applications,
                    COUNT(DISTINCT id) FILTER (
                        WHERE application_count = 1
                          AND lower(application_status) <> profile_status
                    ) AS status_mismatch,
                    COUNT(DISTINCT id) FILTER (
                        WHERE application_count = 1
                          AND (
                              can_deliver IS DISTINCT FROM (signup_as IN ('LIVREUR', 'BOTH'))
                              OR can_transport_people IS DISTINCT FROM (signup_as IN ('TRANSPORTEUR', 'BOTH'))
                          )
                    ) AS activity_mismatch
                FROM (
                    SELECT
                        profile.id,
                        profile.validation_status AS profile_status,
                        profile.can_deliver,
                        profile.can_transport_people,
                        account.account_type,
                        COUNT(application.id) OVER (PARTITION BY profile.id) AS application_count,
                        application.status AS application_status,
                        application.signup_as
                    FROM provider_profile profile
                    JOIN user_account account ON account.id = profile.user_id
                    LEFT JOIN driver_application application ON application.user_id = profile.user_id
                    WHERE NOT EXISTS (
                        SELECT 1
                        FROM provider_application canonical
                        WHERE canonical.provider_profile_id = profile.id
                    )
                ) source
                SQL,
        );

        return [
            'accountTypeMismatch' => (int) ($row['account_type_mismatch'] ?? 0),
            'multipleApplications' => (int) ($row['multiple_applications'] ?? 0),
            'statusMismatch' => (int) ($row['status_mismatch'] ?? 0),
            'activityMismatch' => (int) ($row['activity_mismatch'] ?? 0),
        ];
    }

    private function countEligibleProfiles(): int
    {
        return (int) $this->db->fetchOne(
            <<<'SQL'
                SELECT COUNT(*)
                FROM provider_profile profile
                JOIN user_account account ON account.id = profile.user_id
                LEFT JOIN driver_application application ON application.user_id = profile.user_id
                WHERE account.account_type = 'provider'
                  AND NOT EXISTS (
                      SELECT 1
                      FROM provider_application canonical
                      WHERE canonical.provider_profile_id = profile.id
                  )
                  AND (
                      application.id IS NULL
                      OR (
                          (SELECT COUNT(*) FROM driver_application candidate WHERE candidate.user_id = profile.user_id) = 1
                          AND lower(application.status) = profile.validation_status
                          AND profile.can_deliver = (application.signup_as IN ('LIVREUR', 'BOTH'))
                          AND profile.can_transport_people = (application.signup_as IN ('TRANSPORTEUR', 'BOTH'))
                      )
                  )
                SQL,
        );
    }

    private function canonicalApplicationStatus(string $legacyStatus): string
    {
        return match (strtoupper($legacyStatus)) {
            'PENDING' => 'SUBMITTED',
            'APPROVED', 'SUSPENDED' => 'APPROVED',
            'REJECTED' => 'REJECTED',
            default => throw new \UnexpectedValueException(sprintf('Statut legacy inconnu: %s.', $legacyStatus)),
        };
    }

    private function canonicalAuthorizationStatus(string $profileStatus): string
    {
        return match (strtolower($profileStatus)) {
            'approved' => 'ACTIVE',
            'suspended' => 'SUSPENDED',
            'pending', 'rejected' => 'INACTIVE',
            default => throw new \UnexpectedValueException(sprintf('Statut de profil inconnu: %s.', $profileStatus)),
        };
    }

    /**
     * @param array<string, mixed> $candidate
     * @return non-empty-list<string>
     */
    private function activities(array $candidate): array
    {
        $activities = [];
        if ($this->toBoolean($candidate['can_deliver'])) {
            $activities[] = 'DELIVERY';
        }
        if ($this->toBoolean($candidate['can_transport_people'])) {
            $activities[] = 'PEOPLE_TRANSPORT';
        }

        if ($activities === []) {
            throw new \UnexpectedValueException('Le profil legacy ne contient aucune activite.');
        }

        return $activities;
    }

    /**
     * @param array<string, mixed> $candidate
     * @return array<string, mixed>
     */
    private function profileData(array $candidate): array
    {
        return [
            'source' => 'legacy_backfill',
            'identity' => [
                'phone' => (string) $candidate['phone'],
                'name' => $candidate['name'],
                'email' => $candidate['email'],
                'identityDocumentPath' => $candidate['identity_document_path'],
                'identityDocumentNumber' => $candidate['identity_document_number'],
                'driverLicensePath' => $candidate['driver_license_path'],
            ],
            'legacyApplication' => $candidate['legacy_application_id'] === null ? null : [
                'id' => (int) $candidate['legacy_application_id'],
                'signupAs' => (string) $candidate['signup_as'],
                'status' => (string) $candidate['application_status'],
                'vehicle' => $this->decodeJson($candidate['vehicle']),
                'driverLicense' => $this->decodeJson($candidate['driver_license']),
                'vehicleDocuments' => $this->decodeJson($candidate['vehicle_documents']) ?? [],
                'vehiclePhotos' => $this->decodeJson($candidate['vehicle_photos']) ?? [],
                'deliveryZones' => $this->decodeJson($candidate['delivery_zones']) ?? [],
            ],
        ];
    }

    private function decodeJson(mixed $value): mixed
    {
        if ($value === null || is_array($value)) {
            return $value;
        }

        return json_decode((string) $value, true, flags: JSON_THROW_ON_ERROR);
    }

    private function encodeJson(mixed $value): string
    {
        return (string) json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    private function toBoolean(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }
}
