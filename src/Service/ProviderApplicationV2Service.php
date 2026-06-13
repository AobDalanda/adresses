<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

class ProviderApplicationV2Service
{
    private const ACTIVITIES = ['DELIVERY', 'PEOPLE_TRANSPORT'];

    private const DOCUMENT_TYPES = [
        'IDENTITY_FRONT' => ['identity_document'],
        'IDENTITY_BACK' => ['identity_document'],
        'DRIVER_LICENSE_FRONT' => ['driver_license'],
        'DRIVER_LICENSE_BACK' => ['driver_license'],
        'VEHICLE_INSURANCE' => ['vehicle_insurance'],
        'VEHICLE_REGISTRATION' => [
            'vehicle_registration',
            'vehicle_registration_front',
            'vehicle_registration_back',
        ],
        'VEHICLE_PHOTO' => ['vehicle_photo'],
    ];

    private const DOCUMENT_ALIASES = [
        'IDENTITY' => 'IDENTITY_FRONT',
        'DRIVER_LICENSE' => 'DRIVER_LICENSE_FRONT',
    ];

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @param list<string> $activities
     * @return array{status: int, body: array<string, mixed>}
     */
    public function createDraft(int $userId, array $activities, string $idempotencyKey): array
    {
        $activities = array_values(array_unique($activities));
        $this->assertActivities($activities);
        sort($activities);
        $requestHash = $this->requestHash(['activities' => $activities]);
        $operation = 'provider.application.create';

        return $this->db->transactional(function () use (
            $userId,
            $activities,
            $idempotencyKey,
            $requestHash,
            $operation,
        ): array {
            $this->lockIdempotency($userId, $operation, $idempotencyKey);
            $cached = $this->loadIdempotentResponse($userId, $operation, $idempotencyKey, $requestHash);
            if ($cached !== null) {
                return $cached;
            }

            $profileId = $this->ensureProviderProfile($userId, $activities);
            $existing = $this->db->fetchAssociative(
                <<<'SQL'
                    SELECT
                        application.public_id,
                        application.status,
                        revision.version,
                        revision.activities,
                        application.lock_version
                    FROM provider_application application
                    LEFT JOIN provider_application_revision revision ON revision.id = application.current_revision_id
                    WHERE application.provider_profile_id = :profileId
                      AND application.status IN (
                          'DRAFT',
                          'SUBMITTED',
                          'AUTO_CHECK',
                          'UNDER_REVIEW',
                          'CORRECTION_REQUIRED',
                          'RESUBMITTED'
                      )
                    ORDER BY application.id DESC
                    LIMIT 1
                    FOR UPDATE OF application
                    SQL,
                ['profileId' => $profileId],
            );

            if ($existing !== false) {
                if ((string) $existing['status'] !== 'DRAFT') {
                    throw new \DomainException('Une candidature prestataire est deja ouverte.', 409);
                }
                $existingActivities = $this->decodeStringList($existing['activities']);
                sort($existingActivities);
                if ($existingActivities !== $activities) {
                    throw new \DomainException(
                        'Le brouillon existant contient des activites differentes.',
                        409,
                    );
                }
                $body = $this->applicationBody(
                    (string) $existing['public_id'],
                    'DRAFT',
                    (int) ($existing['version'] ?? 1),
                    (int) $existing['lock_version'],
                );
                $this->storeIdempotentResponse($userId, $operation, $idempotencyKey, $requestHash, 200, $body);

                return ['status' => 200, 'body' => $body];
            }

            $publicId = Uuid::v7()->toRfc4122();
            $applicationId = (int) $this->db->fetchOne(
                <<<'SQL'
                    INSERT INTO provider_application (
                        public_id,
                        provider_profile_id,
                        status,
                        created_at,
                        updated_at
                    )
                    VALUES (:publicId, :profileId, 'DRAFT', now(), now())
                    RETURNING id
                    SQL,
                ['publicId' => $publicId, 'profileId' => $profileId],
            );
            $revisionId = (int) $this->db->fetchOne(
                <<<'SQL'
                    INSERT INTO provider_application_revision (
                        application_id,
                        version,
                        activities,
                        profile_data,
                        created_by,
                        created_at
                    )
                    VALUES (
                        :applicationId,
                        1,
                        CAST(:activities AS jsonb),
                        '{}'::jsonb,
                        :userId,
                        now()
                    )
                    RETURNING id
                    SQL,
                [
                    'applicationId' => $applicationId,
                    'activities' => $this->encodeJson($activities),
                    'userId' => $userId,
                ],
            );
            $this->db->executeStatement(
                'UPDATE provider_application SET current_revision_id = :revisionId WHERE id = :applicationId',
                ['revisionId' => $revisionId, 'applicationId' => $applicationId],
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
                        now(),
                        now()
                    )
                    ON CONFLICT (provider_profile_id) DO NOTHING
                    SQL,
                [
                    'profileId' => $profileId,
                    'applicationId' => $applicationId,
                    'revisionId' => $revisionId,
                ],
            );
            $this->recordOutbox(
                'provider_application',
                $publicId,
                'provider.application.draft_created',
                ['applicationId' => $publicId, 'revision' => 1, 'activities' => $activities],
            );

            $body = $this->applicationBody($publicId, 'DRAFT', 1, 1);
            $this->storeIdempotentResponse($userId, $operation, $idempotencyKey, $requestHash, 201, $body);

            return ['status' => 201, 'body' => $body];
        });
    }

    /**
     * @param array<string, list<string>> $documentAssetIds
     * @return array{status: int, body: array<string, mixed>}
     */
    public function submit(
        int $userId,
        string $applicationPublicId,
        int $revision,
        array $documentAssetIds,
        string $idempotencyKey,
    ): array {
        if (!Uuid::isValid($applicationPublicId)) {
            throw new \InvalidArgumentException('applicationId doit etre un UUID valide.');
        }
        $normalizedDocuments = $this->normalizeDocuments($documentAssetIds);
        $requestHash = $this->requestHash([
            'applicationId' => $applicationPublicId,
            'revision' => $revision,
            'documentAssetIds' => $normalizedDocuments,
        ]);
        $operation = sprintf('provider.application.submit:%s', $applicationPublicId);

        return $this->db->transactional(function () use (
            $userId,
            $applicationPublicId,
            $revision,
            $normalizedDocuments,
            $idempotencyKey,
            $requestHash,
            $operation,
        ): array {
            $this->lockIdempotency($userId, $operation, $idempotencyKey);
            $cached = $this->loadIdempotentResponse($userId, $operation, $idempotencyKey, $requestHash);
            if ($cached !== null) {
                return $cached;
            }

            $application = $this->db->fetchAssociative(
                <<<'SQL'
                    SELECT
                        application.id,
                        application.public_id,
                        application.status,
                        application.current_revision_id,
                        application.lock_version,
                        revision.version,
                        revision.activities
                    FROM provider_application application
                    JOIN provider_profile profile ON profile.id = application.provider_profile_id
                    JOIN provider_application_revision revision ON revision.id = application.current_revision_id
                    WHERE application.public_id = :publicId
                      AND profile.user_id = :userId
                    FOR UPDATE OF application
                    SQL,
                ['publicId' => $applicationPublicId, 'userId' => $userId],
            );
            if ($application === false) {
                throw new \DomainException('Candidature prestataire introuvable.', 404);
            }
            if ((string) $application['status'] !== 'DRAFT') {
                throw new \DomainException('La candidature ne peut plus etre soumise depuis son statut actuel.', 409);
            }
            if ((int) $application['version'] !== $revision) {
                throw new \DomainException('La revision demandee n est plus courante.', 409);
            }

            $activities = $this->decodeStringList($application['activities']);
            $this->assertRequiredDocuments($normalizedDocuments, $activities);
            $assets = $this->loadOwnedAssets($userId, $applicationPublicId, $normalizedDocuments);
            $expectedAssetCount = array_sum(array_map('count', $normalizedDocuments));
            if (count($assets) !== $expectedAssetCount) {
                throw new \DomainException('Un ou plusieurs assets sont absents, invalides ou non possedes.', 422);
            }

            $revisionId = (int) $application['current_revision_id'];
            foreach ($assets as $asset) {
                $documentType = $this->documentTypeForAsset(
                    (string) $asset['public_id'],
                    (string) $asset['category'],
                    $normalizedDocuments,
                );
                $this->db->executeStatement(
                    <<<'SQL'
                        INSERT INTO provider_document (
                            revision_id,
                            asset_id,
                            document_type,
                            side,
                            checksum_sha256,
                            verification_status,
                            created_at
                        )
                        VALUES (
                            :revisionId,
                            :assetId,
                            :documentType,
                            :side,
                            :checksum,
                            'VALID',
                            now()
                        )
                        SQL,
                    [
                        'revisionId' => $revisionId,
                        'assetId' => (int) $asset['id'],
                        'documentType' => $documentType,
                        'side' => str_ends_with($documentType, '_FRONT')
                            ? 'FRONT'
                            : (str_ends_with($documentType, '_BACK') ? 'BACK' : null),
                        'checksum' => (string) $asset['checksum_sha256'],
                    ],
                );
            }

            $assetIds = array_map(static fn (array $asset): int => (int) $asset['id'], $assets);
            $consumed = $this->db->executeStatement(
                <<<'SQL'
                    UPDATE uploaded_asset
                    SET consumed_at = now()
                    WHERE id IN (:assetIds)
                      AND consumed_at IS NULL
                      AND validation_status = 'VALID'
                    SQL,
                ['assetIds' => $assetIds],
                ['assetIds' => \Doctrine\DBAL\ArrayParameterType::INTEGER],
            );
            if ($consumed !== count($assetIds)) {
                throw new \DomainException('Un asset a deja ete consomme.', 409);
            }
            $this->db->executeStatement(
                <<<'SQL'
                    UPDATE upload_session
                    SET status = 'COMPLETED', updated_at = now()
                    WHERE id IN (
                        SELECT DISTINCT session_id
                        FROM uploaded_asset
                        WHERE id IN (:assetIds)
                    )
                      AND status = 'OPEN'
                    SQL,
                ['assetIds' => $assetIds],
                ['assetIds' => \Doctrine\DBAL\ArrayParameterType::INTEGER],
            );

            $occurredAt = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
            $this->db->executeStatement(
                'UPDATE provider_application_revision SET submitted_at = :submittedAt WHERE id = :revisionId AND submitted_at IS NULL',
                ['submittedAt' => $occurredAt, 'revisionId' => $revisionId],
            );
            $this->db->executeStatement(
                <<<'SQL'
                    UPDATE provider_application
                    SET status = 'SUBMITTED',
                        submitted_at = :submittedAt,
                        updated_at = :submittedAt,
                        lock_version = lock_version + 1
                    WHERE id = :applicationId
                    SQL,
                ['submittedAt' => $occurredAt, 'applicationId' => (int) $application['id']],
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
                        :userId,
                        'V2_SECURE_SUBMISSION',
                        CAST(:metadata AS jsonb),
                        :occurredAt,
                        :correlationId
                    )
                    SQL,
                [
                    'applicationId' => (int) $application['id'],
                    'revisionId' => $revisionId,
                    'userId' => $userId,
                    'metadata' => $this->encodeJson([
                        'source' => 'api_v2',
                        'documentCount' => count($assets),
                    ]),
                    'occurredAt' => $occurredAt,
                    'correlationId' => $correlationId,
                ],
            );
            $this->recordOutbox(
                'provider_application',
                $applicationPublicId,
                'provider.application.submitted',
                [
                    'applicationId' => $applicationPublicId,
                    'revision' => $revision,
                    'activities' => $activities,
                    'documentCount' => count($assets),
                    'correlationId' => $correlationId,
                ],
            );

            $body = [
                'applicationId' => $applicationPublicId,
                'status' => 'SUBMITTED',
                'effectiveStatus' => 'SUBMITTED',
                'revision' => $revision,
                'version' => (int) $application['lock_version'] + 1,
            ];
            $this->storeIdempotentResponse($userId, $operation, $idempotencyKey, $requestHash, 202, $body);

            return ['status' => 202, 'body' => $body];
        });
    }

    /**
     * @param list<string> $activities
     */
    private function assertActivities(array $activities): void
    {
        if ($activities === [] || array_diff($activities, self::ACTIVITIES) !== []) {
            throw new \InvalidArgumentException('activities doit contenir une activite prestataire valide.');
        }
    }

    /**
     * @param list<string> $activities
     */
    private function ensureProviderProfile(int $userId, array $activities): int
    {
        $accountType = $this->db->fetchOne(
            'SELECT account_type FROM user_account WHERE id = :userId FOR UPDATE',
            ['userId' => $userId],
        );
        if ($accountType === false) {
            throw new \DomainException('Utilisateur introuvable.', 404);
        }
        if ($accountType === 'admin') {
            throw new \DomainException('Un administrateur ne peut pas creer une candidature prestataire.', 403);
        }

        $this->db->executeStatement(
            "UPDATE user_account SET account_type = 'provider' WHERE id = :userId",
            ['userId' => $userId],
        );

        return (int) $this->db->fetchOne(
            <<<'SQL'
                INSERT INTO provider_profile (
                    user_id,
                    can_deliver,
                    can_transport_people,
                    validation_status,
                    created_at,
                    updated_at
                )
                VALUES (:userId, :canDeliver, :canTransportPeople, 'pending', now(), now())
                ON CONFLICT (user_id) DO UPDATE
                SET can_deliver = EXCLUDED.can_deliver,
                    can_transport_people = EXCLUDED.can_transport_people,
                    updated_at = now()
                RETURNING id
                SQL,
            [
                'userId' => $userId,
                'canDeliver' => in_array('DELIVERY', $activities, true),
                'canTransportPeople' => in_array('PEOPLE_TRANSPORT', $activities, true),
            ],
        );
    }

    /**
     * @param array<string, list<string>> $documents
     * @return array<string, list<string>>
     */
    private function normalizeDocuments(array $documents): array
    {
        if ($documents === []) {
            throw new \InvalidArgumentException('documentAssetIds est requis.');
        }

        $normalized = [];
        foreach ($documents as $documentType => $assetIds) {
            $documentType = self::DOCUMENT_ALIASES[$documentType] ?? $documentType;
            if (!isset(self::DOCUMENT_TYPES[$documentType]) || !is_array($assetIds) || $assetIds === []) {
                throw new \InvalidArgumentException(sprintf('Document invalide: %s.', $documentType));
            }
            foreach ($assetIds as $assetId) {
                if (!is_string($assetId) || !Uuid::isValid($assetId)) {
                    throw new \InvalidArgumentException('Chaque assetId doit etre un UUID valide.');
                }
            }
            $normalized[$documentType] = array_values(array_unique([
                ...($normalized[$documentType] ?? []),
                ...$assetIds,
            ]));
            sort($normalized[$documentType]);
        }
        ksort($normalized);

        return $normalized;
    }

    /**
     * @param array<string, list<string>> $documents
     * @param list<string> $activities
     */
    private function assertRequiredDocuments(array $documents, array $activities): void
    {
        if (!isset($documents['IDENTITY_FRONT']) && !isset($documents['IDENTITY_BACK'])) {
            throw new \DomainException('Au moins un document d identite est obligatoire.', 422);
        }
        if (
            in_array('PEOPLE_TRANSPORT', $activities, true)
            && !isset($documents['DRIVER_LICENSE_FRONT'])
            && !isset($documents['DRIVER_LICENSE_BACK'])
        ) {
            throw new \DomainException('Un permis de conduire est obligatoire pour le transport de personnes.', 422);
        }
    }

    /**
     * @param array<string, list<string>> $documents
     * @return list<array<string, mixed>>
     */
    private function loadOwnedAssets(int $userId, string $applicationPublicId, array $documents): array
    {
        $assetIds = [];
        foreach ($documents as $ids) {
            array_push($assetIds, ...$ids);
        }

        return $this->db->fetchAllAssociative(
            <<<'SQL'
                SELECT asset.id, asset.public_id, asset.category, asset.checksum_sha256
                FROM uploaded_asset asset
                JOIN upload_session session ON session.id = asset.session_id
                LEFT JOIN provider_application application ON application.id = session.provider_application_id
                WHERE asset.public_id IN (:assetIds)
                  AND session.user_id = :userId
                  AND session.purpose = 'PROVIDER_APPLICATION'
                  AND session.status IN ('OPEN', 'COMPLETED')
                  AND session.expires_at > now()
                  AND (session.provider_application_id IS NULL OR application.public_id = :applicationPublicId)
                  AND asset.validation_status = 'VALID'
                  AND asset.consumed_at IS NULL
                FOR UPDATE OF asset
                SQL,
            [
                'assetIds' => $assetIds,
                'userId' => $userId,
                'applicationPublicId' => $applicationPublicId,
            ],
            ['assetIds' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );
    }

    /**
     * @param array<string, list<string>> $documents
     */
    private function documentTypeForAsset(string $assetId, string $category, array $documents): string
    {
        foreach ($documents as $documentType => $assetIds) {
            if (in_array($assetId, $assetIds, true)) {
                if (!in_array($category, self::DOCUMENT_TYPES[$documentType], true)) {
                    throw new \DomainException(
                        sprintf('L asset %s ne correspond pas au type %s.', $assetId, $documentType),
                        422,
                    );
                }

                return $documentType;
            }
        }

        throw new \LogicException('Asset non reference dans la soumission.');
    }

    /**
     * @return array{status: int, body: array<string, mixed>}|null
     */
    private function loadIdempotentResponse(
        int $userId,
        string $operation,
        string $key,
        string $requestHash,
    ): ?array {
        $record = $this->db->fetchAssociative(
            <<<'SQL'
                SELECT request_hash, response_status, response_body
                FROM provider_idempotency_record
                WHERE user_id = :userId
                  AND operation = :operation
                  AND idempotency_key = :idempotencyKey
                  AND expires_at > now()
                FOR UPDATE
                SQL,
            ['userId' => $userId, 'operation' => $operation, 'idempotencyKey' => $key],
        );
        if ($record === false) {
            return null;
        }
        if (!hash_equals((string) $record['request_hash'], $requestHash)) {
            throw new \DomainException('Idempotency-Key reutilisee avec un payload different.', 409);
        }

        $body = is_array($record['response_body'])
            ? $record['response_body']
            : json_decode((string) $record['response_body'], true, flags: JSON_THROW_ON_ERROR);

        return ['status' => (int) $record['response_status'], 'body' => $body];
    }

    private function lockIdempotency(int $userId, string $operation, string $key): void
    {
        $this->db->executeQuery(
            'SELECT pg_advisory_xact_lock(hashtextextended(:lockKey, 0))',
            ['lockKey' => sprintf('%d:%s:%s', $userId, $operation, $key)],
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function storeIdempotentResponse(
        int $userId,
        string $operation,
        string $key,
        string $requestHash,
        int $status,
        array $body,
    ): void {
        $this->db->executeStatement(
            <<<'SQL'
                INSERT INTO provider_idempotency_record (
                    user_id,
                    operation,
                    idempotency_key,
                    request_hash,
                    response_status,
                    response_body,
                    created_at,
                    expires_at
                )
                VALUES (
                    :userId,
                    :operation,
                    :idempotencyKey,
                    :requestHash,
                    :responseStatus,
                    CAST(:responseBody AS jsonb),
                    now(),
                    now() + INTERVAL '24 hours'
                )
                SQL,
            [
                'userId' => $userId,
                'operation' => $operation,
                'idempotencyKey' => $key,
                'requestHash' => $requestHash,
                'responseStatus' => $status,
                'responseBody' => $this->encodeJson($body),
            ],
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function requestHash(array $payload): string
    {
        return hash('sha256', $this->encodeJson($payload));
    }

    /**
     * @return list<string>
     */
    private function decodeStringList(mixed $value): array
    {
        $decoded = is_array($value)
            ? $value
            : json_decode((string) $value, true, flags: JSON_THROW_ON_ERROR);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function applicationBody(string $applicationId, string $status, int $revision, int $version): array
    {
        return [
            'applicationId' => $applicationId,
            'status' => $status,
            'effectiveStatus' => $status,
            'revision' => $revision,
            'version' => $version,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function recordOutbox(
        string $aggregateType,
        string $aggregateId,
        string $eventName,
        array $payload,
    ): void {
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
                    :aggregateType,
                    :aggregateId,
                    :eventName,
                    CAST(:payload AS jsonb),
                    now()
                )
                SQL,
            [
                'id' => Uuid::v7()->toRfc4122(),
                'aggregateType' => $aggregateType,
                'aggregateId' => $aggregateId,
                'eventName' => $eventName,
                'payload' => $this->encodeJson($payload),
            ],
        );
    }

    private function encodeJson(mixed $value): string
    {
        return (string) json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }
}
