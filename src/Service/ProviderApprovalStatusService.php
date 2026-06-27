<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;

final class ProviderApprovalStatusService
{
    public const STEP_ACCOUNT_CREATED = 'ACCOUNT_CREATED';
    public const STEP_DOCUMENTS_SUBMITTED = 'DOCUMENTS_SUBMITTED';
    public const STEP_VERIFICATION_IN_PROGRESS = 'VERIFICATION_IN_PROGRESS';
    public const STEP_ACCOUNT_ACTIVATED = 'ACCOUNT_ACTIVATED';

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function getForUserId(int $userId): array
    {
        $user = $this->db->fetchAssociative(
            <<<'SQL'
                SELECT id, phone, verified, account_type, created_at
                FROM user_account
                WHERE id = :userId
                LIMIT 1
                SQL,
            ['userId' => $userId],
        );
        if ($user === false) {
            throw new \RuntimeException('Utilisateur introuvable');
        }
        $profile = $this->db->fetchAssociative(
            <<<'SQL'
                SELECT id, validation_status, created_at, updated_at
                FROM provider_profile
                WHERE user_id = :userId
                LIMIT 1
                SQL,
            ['userId' => $userId],
        );
        if ($profile === false) {
            throw new \RuntimeException('Profil prestataire introuvable');
        }

        $application = $profile === false ? false : $this->db->fetchAssociative(
            <<<'SQL'
                SELECT
                    application.id,
                    application.public_id,
                    application.status,
                    application.submitted_at,
                    application.created_at,
                    application.updated_at,
                    revision.id AS revision_id,
                    revision.version,
                    revision.submitted_at AS revision_submitted_at
                FROM provider_application application
                LEFT JOIN provider_application_revision revision ON revision.id = application.current_revision_id
                WHERE application.provider_profile_id = :profileId
                ORDER BY application.id DESC
                LIMIT 1
                SQL,
            ['profileId' => (int) $profile['id']],
        );

        $authorization = $profile === false ? false : $this->db->fetchAssociative(
            <<<'SQL'
                SELECT
                    status,
                    created_at,
                    updated_at,
                    suspended_at,
                    reactivated_at
                FROM provider_authorization
                WHERE provider_profile_id = :profileId
                LIMIT 1
                SQL,
            ['profileId' => (int) $profile['id']],
        );

        $documents = $application === false ? [
            'submitted' => false,
            'requiredCount' => 0,
            'submittedCount' => 0,
            'missingTypes' => [],
        ] : $this->documentSummary((int) $application['revision_id']);

        $automaticCheck = $application === false
            ? null
            : $this->db->fetchOne(
                <<<'SQL'
                    SELECT status
                    FROM provider_automatic_check
                    WHERE revision_id = :revisionId
                    ORDER BY completed_at DESC NULLS LAST, id DESC
                    LIMIT 1
                    SQL,
                ['revisionId' => (int) $application['revision_id']],
            );

        return [
            'currentStep' => $this->currentStep($profile, $application, $authorization, $documents),
            'rawStatus' => [
                'profileValidationStatus' => $profile === false ? null : (string) $profile['validation_status'],
                'applicationStatus' => $application === false ? null : (string) $application['status'],
                'authorizationStatus' => $authorization === false ? null : (string) $authorization['status'],
            ],
            'account' => [
                'phone' => (string) $user['phone'],
                'verified' => $this->toBoolean($user['verified']),
                'accountType' => 'provider',
            ],
            'documents' => $documents,
            'checks' => [
                'automaticCheck' => $automaticCheck !== false ? $automaticCheck : null,
            ],
            'timeline' => [
                'accountCreatedAt' => (string) $user['created_at'],
                'documentsSubmittedAt' => $this->documentsSubmittedAt($application),
                'reviewStartedAt' => $this->reviewStartedAt($application),
                'activatedAt' => $this->activatedAt($authorization),
            ],
        ];
    }

    /**
     * @param array<string, mixed>|false $profile
     * @param array<string, mixed>|false $application
     * @param array<string, mixed>|false $authorization
     * @param array{submitted: bool, requiredCount: int, submittedCount: int, missingTypes: list<string>} $documents
     */
    public function currentStep(
        array|false $profile,
        array|false $application,
        array|false $authorization,
        array $documents,
    ): string {
        $authorizationStatus = $authorization === false ? null : (string) $authorization['status'];
        if ($authorizationStatus === 'ACTIVE') {
            return self::STEP_ACCOUNT_ACTIVATED;
        }

        $applicationStatus = $application === false ? null : (string) $application['status'];
        if (in_array($applicationStatus, ['AUTO_CHECK', 'UNDER_REVIEW', 'CORRECTION_REQUIRED', 'RESUBMITTED'], true)) {
            return self::STEP_VERIFICATION_IN_PROGRESS;
        }

        if ($applicationStatus === 'SUBMITTED' || $documents['submitted']) {
            return self::STEP_DOCUMENTS_SUBMITTED;
        }

        if ($profile !== false) {
            return self::STEP_ACCOUNT_CREATED;
        }

        return self::STEP_ACCOUNT_CREATED;
    }

    /**
     * @return array{submitted: bool, requiredCount: int, submittedCount: int, missingTypes: list<string>}
     */
    private function documentSummary(int $revisionId): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT DISTINCT document_type FROM provider_document WHERE revision_id = :revisionId',
            ['revisionId' => $revisionId],
        );
        $types = array_values(array_map(static fn (array $row): string => (string) $row['document_type'], $rows));
        sort($types);

        $required = ['IDENTITY_FRONT', 'IDENTITY_BACK'];
        $missing = array_values(array_diff($required, $types));

        return [
            'submitted' => $types !== [],
            'requiredCount' => count($required),
            'submittedCount' => count($types),
            'missingTypes' => $missing,
        ];
    }

    /**
     * @param array<string, mixed>|false $application
     */
    private function documentsSubmittedAt(array|false $application): ?string
    {
        if ($application === false) {
            return null;
        }

        $submittedAt = $application['submitted_at'] ?? $application['revision_submitted_at'] ?? null;

        return is_string($submittedAt) && $submittedAt !== '' ? $submittedAt : null;
    }

    /**
     * @param array<string, mixed>|false $application
     */
    private function reviewStartedAt(array|false $application): ?string
    {
        if ($application === false) {
            return null;
        }

        $status = (string) $application['status'];
        if (!in_array($status, ['AUTO_CHECK', 'UNDER_REVIEW', 'CORRECTION_REQUIRED', 'RESUBMITTED', 'APPROVED', 'REJECTED'], true)) {
            return null;
        }

        return isset($application['updated_at']) && is_string($application['updated_at'])
            ? $application['updated_at']
            : null;
    }

    /**
     * @param array<string, mixed>|false $authorization
     */
    private function activatedAt(array|false $authorization): ?string
    {
        if ($authorization === false || (string) $authorization['status'] !== 'ACTIVE') {
            return null;
        }

        if (isset($authorization['reactivated_at']) && is_string($authorization['reactivated_at']) && $authorization['reactivated_at'] !== '') {
            return (string) $authorization['reactivated_at'];
        }

        return isset($authorization['updated_at']) && is_string($authorization['updated_at'])
            ? (string) $authorization['updated_at']
            : null;
    }

    private function toBoolean(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }
}
