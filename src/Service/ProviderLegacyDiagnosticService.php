<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

class ProviderLegacyDiagnosticService
{
    /**
     * @var array<string, array{severity: string, description: string, sql: string}>
     */
    private const RULES = [
        'APPLICATION_WITHOUT_USER' => [
            'severity' => 'critical',
            'description' => 'Dossier driver sans utilisateur rattache.',
            'sql' => <<<'SQL'
                SELECT
                    application.id AS application_id,
                    application.phone AS application_phone,
                    application.status AS application_status,
                    candidate.id AS candidate_user_id,
                    candidate.account_type AS candidate_account_type,
                    candidate_profile.id AS candidate_profile_id,
                    COUNT(*) OVER() AS issue_total
                FROM driver_application application
                LEFT JOIN user_account candidate ON candidate.phone = application.phone
                LEFT JOIN provider_profile candidate_profile ON candidate_profile.user_id = candidate.id
                WHERE application.user_id IS NULL
                ORDER BY application.id
                LIMIT :limit
                SQL,
        ],
        'PROFILE_WITHOUT_APPLICATION' => [
            'severity' => 'warning',
            'description' => 'Profil prestataire sans dossier driver.',
            'sql' => <<<'SQL'
                SELECT
                    profile.id AS profile_id,
                    profile.user_id,
                    profile.validation_status AS profile_status,
                    COUNT(*) OVER() AS issue_total
                FROM provider_profile profile
                WHERE NOT EXISTS (
                    SELECT 1
                    FROM driver_application application
                    WHERE application.user_id = profile.user_id
                )
                ORDER BY profile.id
                LIMIT :limit
                SQL,
        ],
        'APPLICATION_WITHOUT_PROFILE' => [
            'severity' => 'critical',
            'description' => 'Dossier driver rattache a un utilisateur sans profil prestataire.',
            'sql' => <<<'SQL'
                SELECT
                    application.id AS application_id,
                    application.user_id,
                    application.status AS application_status,
                    application.signup_as,
                    COUNT(*) OVER() AS issue_total
                FROM driver_application application
                LEFT JOIN provider_profile profile ON profile.user_id = application.user_id
                WHERE application.user_id IS NOT NULL
                  AND profile.id IS NULL
                ORDER BY application.id
                LIMIT :limit
                SQL,
        ],
        'PROVIDER_ACCOUNT_WITHOUT_PROFILE' => [
            'severity' => 'critical',
            'description' => 'Compte de type provider sans profil prestataire.',
            'sql' => <<<'SQL'
                SELECT
                    account.id AS user_id,
                    account.account_type,
                    COUNT(*) OVER() AS issue_total
                FROM user_account account
                LEFT JOIN provider_profile profile ON profile.user_id = account.id
                WHERE account.account_type = 'provider'
                  AND profile.id IS NULL
                ORDER BY account.id
                LIMIT :limit
                SQL,
        ],
        'PROFILE_ATTACHED_TO_ADMIN_ACCOUNT' => [
            'severity' => 'critical',
            'description' => 'Profil prestataire rattache a une identite administrative historique.',
            'sql' => <<<'SQL'
                SELECT
                    profile.id AS profile_id,
                    profile.user_id,
                    account.account_type,
                    profile.validation_status AS profile_status,
                    COUNT(*) OVER() AS issue_total
                FROM provider_profile profile
                JOIN user_account account ON account.id = profile.user_id
                WHERE account.account_type = 'admin'
                ORDER BY profile.id
                LIMIT :limit
                SQL,
        ],
        'MULTIPLE_APPLICATIONS_FOR_USER' => [
            'severity' => 'warning',
            'description' => 'Utilisateur rattache a plusieurs dossiers driver.',
            'sql' => <<<'SQL'
                SELECT
                    application.user_id,
                    COUNT(*) AS application_count,
                    COUNT(*) OVER() AS issue_total
                FROM driver_application application
                WHERE application.user_id IS NOT NULL
                GROUP BY application.user_id
                HAVING COUNT(*) > 1
                ORDER BY application.user_id
                LIMIT :limit
                SQL,
        ],
        'STATUS_MISMATCH' => [
            'severity' => 'critical',
            'description' => 'Statut du profil different du statut du dossier driver.',
            'sql' => <<<'SQL'
                SELECT
                    profile.id AS profile_id,
                    application.id AS application_id,
                    profile.user_id,
                    profile.validation_status AS profile_status,
                    application.status AS application_status,
                    COUNT(*) OVER() AS issue_total
                FROM provider_profile profile
                JOIN driver_application application ON application.user_id = profile.user_id
                WHERE lower(application.status) <> profile.validation_status
                ORDER BY profile.user_id, application.id
                LIMIT :limit
                SQL,
        ],
        'ACTIVITY_MISMATCH' => [
            'severity' => 'warning',
            'description' => 'Activites du profil differentes du type de dossier driver.',
            'sql' => <<<'SQL'
                SELECT
                    profile.id AS profile_id,
                    application.id AS application_id,
                    profile.user_id,
                    application.signup_as,
                    profile.can_deliver,
                    profile.can_transport_people,
                    COUNT(*) OVER() AS issue_total
                FROM provider_profile profile
                JOIN driver_application application ON application.user_id = profile.user_id
                WHERE profile.can_deliver IS DISTINCT FROM (
                        application.signup_as IN ('LIVREUR', 'BOTH')
                    )
                   OR profile.can_transport_people IS DISTINCT FROM (
                        application.signup_as IN ('TRANSPORTEUR', 'BOTH')
                    )
                ORDER BY profile.user_id, application.id
                LIMIT :limit
                SQL,
        ],
    ];

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @return array{
     *     totals: array{profiles: int, applications: int},
     *     issueCount: int,
     *     byType: array<string, int>,
     *     issues: list<array{
     *         type: string,
     *         severity: string,
     *         description: string,
     *         context: array<string, mixed>
     *     }>
     * }
     */
    public function diagnose(int $limitPerType = 20): array
    {
        if ($limitPerType < 1 || $limitPerType > 1000) {
            throw new \InvalidArgumentException('La limite doit etre comprise entre 1 et 1000.');
        }

        $totals = $this->db->fetchAssociative(
            <<<'SQL'
                SELECT
                    (SELECT COUNT(*) FROM provider_profile) AS profile_count,
                    (SELECT COUNT(*) FROM driver_application) AS application_count
                SQL
        );

        $issues = [];
        $byType = [];

        foreach (self::RULES as $type => $rule) {
            $rows = $this->db->fetchAllAssociative(
                $rule['sql'],
                ['limit' => $limitPerType],
                ['limit' => ParameterType::INTEGER]
            );

            $byType[$type] = isset($rows[0]['issue_total']) ? (int) $rows[0]['issue_total'] : 0;
            foreach ($rows as $row) {
                unset($row['issue_total']);
                $issues[] = [
                    'type' => $type,
                    'severity' => $rule['severity'],
                    'description' => $rule['description'],
                    'context' => $this->normalizeContext($row),
                ];
            }
        }

        return [
            'totals' => [
                'profiles' => (int) ($totals['profile_count'] ?? 0),
                'applications' => (int) ($totals['application_count'] ?? 0),
            ],
            'issueCount' => array_sum($byType),
            'byType' => $byType,
            'issues' => $issues,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeContext(array $row): array
    {
        $context = [];

        foreach ($row as $key => $value) {
            if ($value === null) {
                continue;
            }

            $context[$this->camelCase((string) $key)] = match (true) {
                is_bool($value) => $value,
                str_ends_with((string) $key, '_id'), $key === 'application_count' => (int) $value,
                $value === 't', $value === 'true' => true,
                $value === 'f', $value === 'false' => false,
                default => $value,
            };
        }

        return $context;
    }

    private function camelCase(string $value): string
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $value))));
    }
}
