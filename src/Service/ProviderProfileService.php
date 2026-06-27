<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final class ProviderProfileService
{
    public const STATUSES = ['pending', 'approved', 'rejected', 'suspended'];

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByUserId(int $userId): ?array
    {
        $row = $this->db->fetchAssociative(
            $this->baseSelect().' WHERE profile.user_id = :userId',
            ['userId' => $userId]
        );

        return $row === false ? null : $this->map($row);
    }

    /**
     * @return array<string, mixed>
     */
    public function submitActivities(int $userId, bool $canDeliver, bool $canTransportPeople): array
    {
        if (!$canDeliver && !$canTransportPeople) {
            throw new \InvalidArgumentException('Au moins une activité est obligatoire');
        }

        return $this->db->transactional(function () use ($userId, $canDeliver, $canTransportPeople): array {
            $accountType = $this->db->fetchOne(
                'SELECT account_type FROM user_account WHERE id = :userId FOR UPDATE',
                ['userId' => $userId]
            );
            if ($accountType === false) {
                throw new \RuntimeException('Utilisateur introuvable');
            }
            if ($accountType === 'admin' || (bool) $this->db->fetchOne(
                'SELECT EXISTS(SELECT 1 FROM back_office_account WHERE user_id = :userId AND enabled = TRUE)',
                ['userId' => $userId]
            )) {
                throw new \DomainException('Un compte administrateur ne peut pas devenir prestataire');
            }
            $this->db->executeStatement(
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
                    validation_status = 'pending',
                    updated_at = now()
                SQL,
                [
                    'userId' => $userId,
                    'canDeliver' => $canDeliver,
                    'canTransportPeople' => $canTransportPeople,
                ],
                [
                    'userId' => ParameterType::INTEGER,
                    'canDeliver' => ParameterType::BOOLEAN,
                    'canTransportPeople' => ParameterType::BOOLEAN,
                ]
            );

            return $this->findByUserId($userId)
                ?? throw new \RuntimeException('Profil prestataire introuvable après enregistrement');
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(?string $status = null, ?bool $canDeliver = null, ?bool $canTransportPeople = null): array
    {
        $where = [];
        $parameters = [];

        if ($status !== null) {
            $where[] = 'profile.validation_status = :status';
            $parameters['status'] = $status;
        }
        if ($canDeliver !== null) {
            $where[] = 'profile.can_deliver = :canDeliver';
            $parameters['canDeliver'] = $canDeliver;
        }
        if ($canTransportPeople !== null) {
            $where[] = 'profile.can_transport_people = :canTransportPeople';
            $parameters['canTransportPeople'] = $canTransportPeople;
        }

        $sql = $this->baseSelect();
        if ($where !== []) {
            $sql .= ' WHERE '.implode(' AND ', $where);
        }
        $sql .= ' ORDER BY profile.created_at DESC, profile.id DESC';

        $types = [];
        if ($canDeliver !== null) {
            $types['canDeliver'] = ParameterType::BOOLEAN;
        }
        if ($canTransportPeople !== null) {
            $types['canTransportPeople'] = ParameterType::BOOLEAN;
        }

        return array_map($this->map(...), $this->db->fetchAllAssociative($sql, $parameters, $types));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $profileId): ?array
    {
        $row = $this->db->fetchAssociative(
            $this->baseSelect().' WHERE profile.id = :profileId',
            ['profileId' => $profileId]
        );

        return $row === false ? null : $this->map($row);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function updateStatus(int $profileId, string $status): ?array
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('validationStatus est invalide');
        }

        $updated = $this->db->executeStatement(
            '
            UPDATE provider_profile
            SET validation_status = :status,
                updated_at = now()
            WHERE id = :profileId
            ',
            ['profileId' => $profileId, 'status' => $status]
        );

        return $updated === 0 ? null : $this->findById($profileId);
    }

    private function baseSelect(): string
    {
        return <<<'SQL'
            SELECT
                profile.id,
                profile.user_id,
                profile.can_deliver,
                profile.can_transport_people,
                profile.validation_status,
                profile.created_at,
                profile.updated_at,
                user_account.phone,
                user_account.name,
                user_account.email,
                user_account.verified,
                user_account.account_type
            FROM provider_profile profile
            JOIN user_account ON user_account.id = profile.user_id
            SQL;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function map(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'userId' => (int) $row['user_id'],
            'canDeliver' => $this->toBoolean($row['can_deliver']),
            'canTransportPeople' => $this->toBoolean($row['can_transport_people']),
            'validationStatus' => (string) $row['validation_status'],
            'createdAt' => (string) $row['created_at'],
            'updatedAt' => (string) $row['updated_at'],
            'user' => [
                'id' => (int) $row['user_id'],
                'phone' => (string) $row['phone'],
                'name' => $row['name'] !== null ? (string) $row['name'] : null,
                'email' => $row['email'] !== null ? (string) $row['email'] : null,
                'verified' => $this->toBoolean($row['verified']),
                // Compatibility field for mobile clients deployed before capabilities.
                'accountType' => 'provider',
            ],
            'capabilities' => [
                'client' => true,
                'provider' => true,
                'providerStatus' => (string) $row['validation_status'],
                'canDeliver' => $this->toBoolean($row['can_deliver']),
                'canTransportPeople' => $this->toBoolean($row['can_transport_people']),
            ],
        ];
    }

    private function toBoolean(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }
}
