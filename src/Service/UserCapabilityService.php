<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;

final class UserCapabilityService
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @return array{
     *     client: bool,
     *     provider: bool,
     *     providerStatus: ?string,
     *     providerActive: bool,
     *     canDeliver: bool,
     *     canTransportPeople: bool,
     *     backOffice: bool
     * }
     */
    public function forUser(int $userId): array
    {
        $row = $this->db->fetchAssociative(
            <<<'SQL'
                SELECT
                    account.account_type,
                    profile.id AS provider_profile_id,
                    profile.validation_status,
                    profile.can_deliver,
                    profile.can_transport_people,
                    provider_auth.status AS authorization_status,
                    back_office.enabled AS back_office_enabled
                FROM user_account account
                LEFT JOIN provider_profile profile ON profile.user_id = account.id
                LEFT JOIN provider_authorization provider_auth ON provider_auth.provider_profile_id = profile.id
                LEFT JOIN back_office_account back_office ON back_office.user_id = account.id
                WHERE account.id = :userId
                LIMIT 1
                SQL,
            ['userId' => $userId],
        );

        if ($row === false) {
            throw new \RuntimeException(sprintf('Utilisateur %d introuvable.', $userId));
        }

        $provider = $row['provider_profile_id'] !== null;
        $providerStatus = $provider && is_string($row['validation_status'])
            ? $row['validation_status']
            : null;

        return [
            'client' => (string) $row['account_type'] !== 'admin',
            'provider' => $provider,
            'providerStatus' => $providerStatus,
            'providerActive' => $providerStatus === 'approved' && $row['authorization_status'] === 'ACTIVE',
            'canDeliver' => $provider && $this->toBoolean($row['can_deliver']),
            'canTransportPeople' => $provider && $this->toBoolean($row['can_transport_people']),
            'backOffice' => $this->toBoolean($row['back_office_enabled']),
        ];
    }

    private function toBoolean(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }
}
