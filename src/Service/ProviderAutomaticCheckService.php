<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

class ProviderAutomaticCheckService
{
    private const ENGINE_VERSION = 'provider-rules-v1';

    public function __construct(private readonly Connection $db)
    {
    }

    public function run(string $applicationPublicId, string $causationId): void
    {
        $application = $this->db->fetchAssociative(
            <<<'SQL'
                SELECT
                    application.id,
                    application.public_id,
                    application.status,
                    application.current_revision_id,
                    revision.activities
                FROM provider_application application
                JOIN provider_application_revision revision ON revision.id = application.current_revision_id
                WHERE application.public_id = :publicId
                FOR UPDATE OF application
                SQL,
            ['publicId' => $applicationPublicId],
        );
        if ($application === false) {
            throw new \RuntimeException('Candidature introuvable pour le controle automatique.');
        }

        $status = (string) $application['status'];
        if ($status === 'UNDER_REVIEW') {
            return;
        }
        if (!in_array($status, ['SUBMITTED', 'RESUBMITTED', 'AUTO_CHECK'], true)) {
            throw new \DomainException(sprintf('Controle automatique impossible depuis %s.', $status));
        }

        $applicationId = (int) $application['id'];
        $revisionId = (int) $application['current_revision_id'];
        if ($status !== 'AUTO_CHECK') {
            $this->transition(
                $applicationId,
                $revisionId,
                $status,
                'AUTO_CHECK',
                'START_AUTO_CHECK',
                'provider.automatic_check.started',
                $applicationPublicId,
                $causationId,
                [],
            );
        }

        $activities = $this->decodeStringList($application['activities']);
        $results = [
            $this->requiredDocumentsCheck($revisionId, $activities),
            $this->documentIntegrityCheck($revisionId),
        ];

        foreach ($results as $result) {
            $this->persistCheck($applicationId, $revisionId, $result);
        }

        $summary = [
            'passed' => count(array_filter($results, static fn (array $result): bool => $result['status'] === 'PASSED')),
            'warnings' => count(array_filter($results, static fn (array $result): bool => $result['status'] === 'WARNING')),
            'errors' => count(array_filter($results, static fn (array $result): bool => $result['status'] === 'ERROR')),
        ];
        $this->transition(
            $applicationId,
            $revisionId,
            'AUTO_CHECK',
            'UNDER_REVIEW',
            'COMPLETE_AUTO_CHECK',
            'provider.automatic_check.completed',
            $applicationPublicId,
            $causationId,
            $summary,
        );
    }

    public function fail(string $applicationPublicId, string $causationId, string $error): void
    {
        $application = $this->db->fetchAssociative(
            <<<'SQL'
                SELECT id, status, current_revision_id
                FROM provider_application
                WHERE public_id = :publicId
                FOR UPDATE
                SQL,
            ['publicId' => $applicationPublicId],
        );
        if ($application === false || (string) $application['status'] === 'UNDER_REVIEW') {
            return;
        }

        $oldStatus = (string) $application['status'];
        if (!in_array($oldStatus, ['SUBMITTED', 'RESUBMITTED', 'AUTO_CHECK'], true)) {
            return;
        }

        $applicationId = (int) $application['id'];
        $revisionId = (int) $application['current_revision_id'];
        foreach (['REQUIRED_DOCUMENTS', 'DOCUMENT_INTEGRITY'] as $checkType) {
            $this->persistCheck($applicationId, $revisionId, [
                'type' => $checkType,
                'status' => 'ERROR',
                'score' => 0.0,
                'details' => ['error' => mb_substr($error, 0, 1000)],
            ]);
        }

        $this->transition(
            $applicationId,
            $revisionId,
            $oldStatus,
            'UNDER_REVIEW',
            'FAIL_AUTO_CHECK',
            'provider.automatic_check.failed',
            $applicationPublicId,
            $causationId,
            ['errors' => 2],
        );
    }

    /**
     * @param list<string> $activities
     * @return array{type: string, status: string, score: float, details: array<string, mixed>}
     */
    private function requiredDocumentsCheck(int $revisionId, array $activities): array
    {
        $types = array_map(
            static fn (array $row): string => (string) $row['document_type'],
            $this->db->fetchAllAssociative(
                'SELECT DISTINCT document_type FROM provider_document WHERE revision_id = :revisionId',
                ['revisionId' => $revisionId],
            ),
        );
        $requiredGroups = [
            'IDENTITY' => ['IDENTITY_FRONT', 'IDENTITY_BACK'],
        ];
        if (in_array('PEOPLE_TRANSPORT', $activities, true)) {
            $requiredGroups['DRIVER_LICENSE'] = ['DRIVER_LICENSE_FRONT', 'DRIVER_LICENSE_BACK'];
        }

        $missing = [];
        foreach ($requiredGroups as $name => $alternatives) {
            if (array_intersect($alternatives, $types) === []) {
                $missing[] = $name;
            }
        }

        return [
            'type' => 'REQUIRED_DOCUMENTS',
            'status' => $missing === [] ? 'PASSED' : 'WARNING',
            'score' => $missing === [] ? 1.0 : 0.5,
            'details' => [
                'requiredGroups' => array_keys($requiredGroups),
                'documentTypes' => $types,
                'missingGroups' => $missing,
            ],
        ];
    }

    /**
     * @return array{type: string, status: string, score: float, details: array<string, mixed>}
     */
    private function documentIntegrityCheck(int $revisionId): array
    {
        $row = $this->db->fetchAssociative(
            <<<'SQL'
                SELECT
                    COUNT(*) AS document_count,
                    COUNT(*) FILTER (
                        WHERE document.verification_status <> 'VALID'
                           OR asset.validation_status <> 'VALID'
                           OR document.checksum_sha256 <> asset.checksum_sha256
                    ) AS invalid_count
                FROM provider_document document
                JOIN uploaded_asset asset ON asset.id = document.asset_id
                WHERE document.revision_id = :revisionId
                SQL,
            ['revisionId' => $revisionId],
        );
        $documentCount = (int) ($row['document_count'] ?? 0);
        $invalidCount = (int) ($row['invalid_count'] ?? 0);

        return [
            'type' => 'DOCUMENT_INTEGRITY',
            'status' => $documentCount > 0 && $invalidCount === 0 ? 'PASSED' : 'WARNING',
            'score' => $documentCount > 0 && $invalidCount === 0 ? 1.0 : 0.0,
            'details' => [
                'documentCount' => $documentCount,
                'invalidCount' => $invalidCount,
            ],
        ];
    }

    /**
     * @param array{type: string, status: string, score: float, details: array<string, mixed>} $result
     */
    private function persistCheck(int $applicationId, int $revisionId, array $result): void
    {
        $this->db->executeStatement(
            <<<'SQL'
                INSERT INTO provider_automatic_check (
                    application_id,
                    revision_id,
                    check_type,
                    status,
                    score,
                    details,
                    engine_version,
                    started_at,
                    completed_at,
                    created_at,
                    updated_at
                )
                VALUES (
                    :applicationId,
                    :revisionId,
                    :checkType,
                    :status,
                    :score,
                    CAST(:details AS jsonb),
                    :engineVersion,
                    now(),
                    now(),
                    now(),
                    now()
                )
                ON CONFLICT (revision_id, check_type) DO UPDATE
                SET status = EXCLUDED.status,
                    score = EXCLUDED.score,
                    details = EXCLUDED.details,
                    engine_version = EXCLUDED.engine_version,
                    completed_at = EXCLUDED.completed_at,
                    updated_at = EXCLUDED.updated_at
                SQL,
            [
                'applicationId' => $applicationId,
                'revisionId' => $revisionId,
                'checkType' => $result['type'],
                'status' => $result['status'],
                'score' => $result['score'],
                'details' => $this->encodeJson($result['details']),
                'engineVersion' => self::ENGINE_VERSION,
            ],
        );
    }

    /**
     * @param array<string, int> $summary
     */
    private function transition(
        int $applicationId,
        int $revisionId,
        string $oldStatus,
        string $newStatus,
        string $transition,
        string $eventName,
        string $applicationPublicId,
        string $causationId,
        array $summary,
    ): void {
        $occurredAt = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $this->db->executeStatement(
            <<<'SQL'
                UPDATE provider_application
                SET status = :newStatus,
                    updated_at = :occurredAt,
                    lock_version = lock_version + 1
                WHERE id = :applicationId
                SQL,
            [
                'applicationId' => $applicationId,
                'newStatus' => $newStatus,
                'occurredAt' => $occurredAt,
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
                    reason_code,
                    metadata,
                    occurred_at,
                    correlation_id,
                    causation_id
                )
                VALUES (
                    :applicationId,
                    :revisionId,
                    :transition,
                    :oldStatus,
                    :newStatus,
                    'SYSTEM',
                    'AUTOMATIC_CHECK',
                    CAST(:metadata AS jsonb),
                    :occurredAt,
                    :correlationId,
                    :causationId
                )
                SQL,
            [
                'applicationId' => $applicationId,
                'revisionId' => $revisionId,
                'transition' => $transition,
                'oldStatus' => $oldStatus,
                'newStatus' => $newStatus,
                'metadata' => $this->encodeJson($summary),
                'occurredAt' => $occurredAt,
                'correlationId' => $correlationId,
                'causationId' => $causationId,
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
                    :eventName,
                    CAST(:payload AS jsonb),
                    :occurredAt
                )
                SQL,
            [
                'id' => Uuid::v7()->toRfc4122(),
                'aggregateId' => $applicationPublicId,
                'eventName' => $eventName,
                'payload' => $this->encodeJson([
                    'applicationId' => $applicationPublicId,
                    'revisionId' => $revisionId,
                    'summary' => $summary,
                    'correlationId' => $correlationId,
                    'causationId' => $causationId,
                ]),
                'occurredAt' => $occurredAt,
            ],
        );
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

    private function encodeJson(mixed $value): string
    {
        return (string) json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }
}
