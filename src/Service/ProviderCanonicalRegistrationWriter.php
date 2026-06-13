<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\DriverRegistrationInput;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

class ProviderCanonicalRegistrationWriter
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function write(int $userId, int $legacyApplicationId, DriverRegistrationInput $input): void
    {
        $profileId = $this->db->fetchOne(
            'SELECT id FROM provider_profile WHERE user_id = :userId FOR UPDATE',
            ['userId' => $userId],
        );
        if ($profileId === false) {
            throw new \RuntimeException('Profil prestataire introuvable pour la double ecriture.');
        }

        $application = $this->db->fetchAssociative(
            <<<'SQL'
                SELECT id, public_id, status, current_revision_id, legacy_driver_application_id
                FROM provider_application
                WHERE provider_profile_id = :profileId
                ORDER BY id DESC
                LIMIT 1
                FOR UPDATE
                SQL,
            ['profileId' => (int) $profileId],
        );

        if ($application === false) {
            $application = $this->createApplication((int) $profileId, $legacyApplicationId);
        } else {
            $this->assertDraftCanBeSubmitted($application, $legacyApplicationId);
        }

        $applicationId = (int) $application['id'];
        $publicId = (string) $application['public_id'];
        $previousRevisionId = $application['current_revision_id'] !== null
            ? (int) $application['current_revision_id']
            : null;
        $version = (int) $this->db->fetchOne(
            'SELECT COALESCE(MAX(version), 0) + 1 FROM provider_application_revision WHERE application_id = :applicationId',
            ['applicationId' => $applicationId],
        );
        $occurredAt = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $revisionId = (int) $this->db->fetchOne(
            <<<'SQL'
                INSERT INTO provider_application_revision (
                    application_id,
                    version,
                    activities,
                    profile_data,
                    submitted_at,
                    supersedes_revision_id,
                    created_by,
                    created_at
                )
                VALUES (
                    :applicationId,
                    :version,
                    CAST(:activities AS jsonb),
                    CAST(:profileData AS jsonb),
                    :submittedAt,
                    :supersedesRevisionId,
                    :createdBy,
                    :createdAt
                )
                RETURNING id
                SQL,
            [
                'applicationId' => $applicationId,
                'version' => $version,
                'activities' => $this->encodeJson($this->activities($input)),
                'profileData' => $this->encodeJson($this->profileData($input, $legacyApplicationId)),
                'submittedAt' => $occurredAt,
                'supersedesRevisionId' => $previousRevisionId,
                'createdBy' => $userId,
                'createdAt' => $occurredAt,
            ],
        );

        $this->db->executeStatement(
            <<<'SQL'
                UPDATE provider_application
                SET current_revision_id = :revisionId,
                    status = 'SUBMITTED',
                    legacy_driver_application_id = :legacyApplicationId,
                    submitted_at = :submittedAt,
                    updated_at = :updatedAt
                WHERE id = :applicationId
                SQL,
            [
                'applicationId' => $applicationId,
                'revisionId' => $revisionId,
                'legacyApplicationId' => $legacyApplicationId,
                'submittedAt' => $occurredAt,
                'updatedAt' => $occurredAt,
            ],
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
                    created_at,
                    updated_at
                )
                VALUES (
                    :profileId,
                    :applicationId,
                    :revisionId,
                    'INACTIVE',
                    FALSE,
                    FALSE,
                    :createdAt,
                    :updatedAt
                )
                ON CONFLICT (provider_profile_id) DO UPDATE
                SET source_application_id = EXCLUDED.source_application_id,
                    source_revision_id = EXCLUDED.source_revision_id,
                    status = 'INACTIVE',
                    can_deliver = FALSE,
                    can_transport_people = FALSE,
                    suspension_reason_code = NULL,
                    suspended_at = NULL,
                    suspended_by = NULL,
                    updated_at = EXCLUDED.updated_at,
                    lock_version = provider_authorization.lock_version + 1
                SQL,
            [
                'profileId' => (int) $profileId,
                'applicationId' => $applicationId,
                'revisionId' => $revisionId,
                'createdAt' => $occurredAt,
                'updatedAt' => $occurredAt,
            ],
        );

        $correlationId = Uuid::v7()->toRfc4122();
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
                    'SUBMIT',
                    'DRAFT',
                    'SUBMITTED',
                    'PROVIDER',
                    :actorId,
                    'V1_REGISTRATION',
                    CAST(:metadata AS jsonb),
                    :occurredAt,
                    :correlationId
                )
                SQL,
            [
                'applicationId' => $applicationId,
                'revisionId' => $revisionId,
                'actorId' => $userId,
                'metadata' => $this->encodeJson([
                    'source' => 'api_v1',
                    'legacyDriverApplicationId' => $legacyApplicationId,
                ]),
                'occurredAt' => $occurredAt,
                'correlationId' => $correlationId,
            ],
        );

        $this->db->executeStatement(
            <<<'SQL'
                INSERT INTO outbox_event (
                    id,
                    aggregate_type,
                    aggregate_id,
                    event_name,
                    payload,
                    occurred_at
                )
                VALUES (
                    :id,
                    'provider_application',
                    :aggregateId,
                    'provider.application.submitted',
                    CAST(:payload AS jsonb),
                    :occurredAt
                )
                SQL,
            [
                'id' => Uuid::v7()->toRfc4122(),
                'aggregateId' => $publicId,
                'payload' => $this->encodeJson([
                    'applicationId' => $publicId,
                    'revision' => $version,
                    'providerProfileId' => (int) $profileId,
                    'legacyDriverApplicationId' => $legacyApplicationId,
                    'correlationId' => $correlationId,
                ]),
                'occurredAt' => $occurredAt,
            ],
        );
    }

    /**
     * @return array{id: int, public_id: string, status: string, current_revision_id: null, legacy_driver_application_id: int}
     */
    private function createApplication(int $profileId, int $legacyApplicationId): array
    {
        $publicId = Uuid::v7()->toRfc4122();
        $applicationId = (int) $this->db->fetchOne(
            <<<'SQL'
                INSERT INTO provider_application (
                    public_id,
                    provider_profile_id,
                    status,
                    legacy_driver_application_id,
                    submitted_at,
                    created_at,
                    updated_at
                )
                VALUES (
                    :publicId,
                    :profileId,
                    'SUBMITTED',
                    :legacyApplicationId,
                    now(),
                    now(),
                    now()
                )
                RETURNING id
                SQL,
            [
                'publicId' => $publicId,
                'profileId' => $profileId,
                'legacyApplicationId' => $legacyApplicationId,
            ],
        );

        return [
            'id' => $applicationId,
            'public_id' => $publicId,
            'status' => 'DRAFT',
            'current_revision_id' => null,
            'legacy_driver_application_id' => $legacyApplicationId,
        ];
    }

    /**
     * @param array<string, mixed> $application
     */
    private function assertDraftCanBeSubmitted(array $application, int $legacyApplicationId): void
    {
        if ((string) $application['status'] !== 'DRAFT') {
            throw new \DomainException('Une candidature prestataire canonique est deja ouverte.');
        }

        $linkedLegacyId = $application['legacy_driver_application_id'];
        if ($linkedLegacyId !== null && (int) $linkedLegacyId !== $legacyApplicationId) {
            throw new \DomainException('La candidature canonique est deja liee a un autre dossier legacy.');
        }
    }

    /** @return non-empty-list<string> */
    private function activities(DriverRegistrationInput $input): array
    {
        return match ($input->signupAs) {
            'LIVREUR' => ['DELIVERY'],
            'TRANSPORTEUR' => ['PEOPLE_TRANSPORT'],
            'BOTH' => ['DELIVERY', 'PEOPLE_TRANSPORT'],
            default => throw new \UnexpectedValueException(sprintf('Activite v1 inconnue: %s.', $input->signupAs)),
        };
    }

    /** @return array<string, mixed> */
    private function profileData(DriverRegistrationInput $input, int $legacyApplicationId): array
    {
        return [
            'source' => 'api_v1',
            'legacyDriverApplicationId' => $legacyApplicationId,
            'profile' => [
                'fullName' => $input->fullName,
                'email' => $input->email,
                'phone' => $input->phone,
                'identityDocumentNumber' => $input->identityDocumentNumber,
                'identityDocumentPath' => $input->identityDocumentPath,
            ],
            'vehicle' => $input->vehicle,
            'driverLicense' => $input->driverLicense,
            'vehicleDocuments' => $input->vehicleDocuments,
            'vehiclePhotoPaths' => $input->vehiclePhotoPaths,
        ];
    }

    private function encodeJson(mixed $value): string
    {
        return (string) json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }
}
