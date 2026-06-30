<?php

declare(strict_types=1);

namespace App\Service;

use App\Util\PhoneNumberNormalizer;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;

final class AdminUserManagementService
{
    public const TYPES = ['BO', 'PRESTATAIRE', 'CLIENT'];
    public const BO_ROLES = [
        'ROLE_ADMIN',
        'ROLE_PROVIDER_REVIEWER',
        'ROLE_PROVIDER_APPROVER',
        'ROLE_PROVIDER_SECURITY_ADMIN',
    ];

    public function __construct(private readonly Connection $db)
    {
    }

    /** @return list<array<string, mixed>> */
    public function list(string $type, ?string $search = null): array
    {
        $type = $this->type($type);
        $where = match ($type) {
            'BO' => 'back_office.user_id IS NOT NULL',
            'PRESTATAIRE' => 'profile.id IS NOT NULL AND back_office.user_id IS NULL',
            'CLIENT' => 'profile.id IS NULL AND back_office.user_id IS NULL',
        };
        $parameters = [];
        if (is_string($search) && trim($search) !== '') {
            $where .= " AND (LOWER(COALESCE(account.name, '')) LIKE :search OR LOWER(COALESCE(account.email, '')) LIKE :search OR account.phone LIKE :search)";
            $parameters['search'] = '%'.mb_strtolower(trim($search)).'%';
        }

        $rows = $this->db->fetchAllAssociative(
            $this->baseSelect()." WHERE {$where} ".$this->groupBy().' ORDER BY account.created_at DESC, account.id DESC',
            $parameters,
        );

        return array_map(fn (array $row): array => $this->map($row, $type), $rows);
    }

    /** @param array<string, mixed> $payload
     *  @return array<string, mixed>
     */
    public function create(array $payload): array
    {
        $type = $this->type($payload['type'] ?? null);
        $phone = $this->phone($payload['phone'] ?? null);
        $name = $this->optionalText($payload['name'] ?? null);
        $email = $this->email($payload['email'] ?? null);

        try {
            $id = $this->db->transactional(function () use ($type, $phone, $name, $email, $payload): int {
                $id = (int) $this->db->fetchOne(
                    <<<'SQL'
                        INSERT INTO user_account (phone, name, email, verified, enabled, account_type, token_version, created_at)
                        VALUES (:phone, :name, :email, TRUE, TRUE, :accountType, 1, now())
                        RETURNING id
                        SQL,
                    ['phone' => $phone, 'name' => $name, 'email' => $email, 'accountType' => $type === 'BO' ? 'admin' : 'client'],
                );

                if ($type === 'BO') {
                    $this->db->insert('back_office_account', ['user_id' => $id]);
                    $this->replaceRoles($id, $this->roles($payload['roles'] ?? ['ROLE_ADMIN']));
                } elseif ($type === 'PRESTATAIRE') {
                    $canDeliver = $this->boolean($payload['canDeliver'] ?? false);
                    $canTransport = $this->boolean($payload['canTransportPeople'] ?? false);
                    if (!$canDeliver && !$canTransport) {
                        throw new \InvalidArgumentException('Au moins une activité prestataire est obligatoire.');
                    }
                    $this->db->executeStatement(
                        <<<'SQL'
                            INSERT INTO provider_profile (user_id, can_deliver, can_transport_people, validation_status, created_at, updated_at)
                            VALUES (:userId, :canDeliver, :canTransport, 'pending', now(), now())
                            SQL,
                        ['userId' => $id, 'canDeliver' => $canDeliver, 'canTransport' => $canTransport],
                        ['userId' => ParameterType::INTEGER, 'canDeliver' => ParameterType::BOOLEAN, 'canTransport' => ParameterType::BOOLEAN],
                    );
                }

                return $id;
            });
        } catch (UniqueConstraintViolationException) {
            throw new \InvalidArgumentException('Ce téléphone ou cet e-mail est déjà utilisé.');
        }

        return $this->find($id, $type) ?? throw new \RuntimeException('Utilisateur créé mais introuvable.');
    }

    /** @param array<string, mixed> $payload
     *  @return array<string, mixed>|null
     */
    public function update(int $id, string $type, array $payload): ?array
    {
        $type = $this->type($type);
        if ($this->find($id, $type) === null) {
            return null;
        }

        $fields = [];
        $parameters = ['id' => $id];
        foreach (['name', 'email'] as $field) {
            if (array_key_exists($field, $payload)) {
                $fields[] = $field.' = :'.$field;
                $parameters[$field] = $field === 'email' ? $this->email($payload[$field]) : $this->optionalText($payload[$field]);
            }
        }
        if (array_key_exists('phone', $payload)) {
            $fields[] = 'phone = :phone';
            $parameters['phone'] = $this->phone($payload['phone']);
        }

        try {
            $this->db->transactional(function () use ($id, $type, $payload, $fields, $parameters): void {
                if ($fields !== []) {
                    $this->db->executeStatement('UPDATE user_account SET '.implode(', ', $fields).' WHERE id = :id', $parameters);
                }
                if ($type === 'BO' && array_key_exists('roles', $payload)) {
                    $this->replaceRoles($id, $this->roles($payload['roles']));
                }
                if ($type === 'PRESTATAIRE') {
                    $updates = [];
                    $providerParameters = ['userId' => $id];
                    foreach (['canDeliver' => 'can_deliver', 'canTransportPeople' => 'can_transport_people'] as $input => $column) {
                        if (array_key_exists($input, $payload)) {
                            $updates[] = $column.' = :'.$input;
                            $providerParameters[$input] = $this->boolean($payload[$input]);
                        }
                    }
                    if ($updates !== []) {
                        $current = $this->find($id, $type);
                        $canDeliver = array_key_exists('canDeliver', $providerParameters)
                            ? $providerParameters['canDeliver']
                            : (bool) ($current['canDeliver'] ?? false);
                        $canTransport = array_key_exists('canTransportPeople', $providerParameters)
                            ? $providerParameters['canTransportPeople']
                            : (bool) ($current['canTransportPeople'] ?? false);
                        if (!$canDeliver && !$canTransport) {
                            throw new \InvalidArgumentException('Au moins une activité prestataire est obligatoire.');
                        }
                        $updates[] = 'updated_at = now()';
                        $this->db->executeStatement('UPDATE provider_profile SET '.implode(', ', $updates).' WHERE user_id = :userId', $providerParameters);
                    }
                }
            });
        } catch (UniqueConstraintViolationException) {
            throw new \InvalidArgumentException('Ce téléphone ou cet e-mail est déjà utilisé.');
        }

        return $this->find($id, $type);
    }

    /** @return array<string, mixed>|null */
    public function setEnabled(int $id, string $type, bool $enabled): ?array
    {
        $type = $this->type($type);
        if ($this->find($id, $type) === null) {
            return null;
        }

        if ($type === 'BO') {
            $this->db->executeStatement(
                'UPDATE back_office_account SET enabled = :enabled, token_version = token_version + 1, updated_at = now() WHERE user_id = :id',
                ['enabled' => $enabled, 'id' => $id],
                ['enabled' => ParameterType::BOOLEAN, 'id' => ParameterType::INTEGER],
            );
        } elseif ($type === 'PRESTATAIRE') {
            $this->db->transactional(function () use ($id, $enabled): void {
                $profile = $this->db->fetchAssociative(
                    'SELECT id, can_deliver, can_transport_people FROM provider_profile WHERE user_id = :id FOR UPDATE',
                    ['id' => $id],
                );
                if ($profile === false) {
                    throw new \RuntimeException('Profil prestataire introuvable.');
                }

                $this->db->executeStatement(
                    'UPDATE provider_profile SET validation_status = :status, updated_at = now() WHERE id = :profileId',
                    ['status' => $enabled ? 'approved' : 'suspended', 'profileId' => $profile['id']],
                );
                $this->db->executeStatement(
                    <<<'SQL'
                        INSERT INTO provider_authorization (
                            provider_profile_id, status, can_deliver, can_transport_people,
                            suspension_reason_code, suspended_at, reactivated_at, created_at, updated_at
                        )
                        VALUES (
                            :profileId, :status, :canDeliver, :canTransport,
                            :reason, :suspendedAt, :reactivatedAt, now(), now()
                        )
                        ON CONFLICT (provider_profile_id) DO UPDATE
                        SET status = EXCLUDED.status,
                            can_deliver = EXCLUDED.can_deliver,
                            can_transport_people = EXCLUDED.can_transport_people,
                            suspension_reason_code = EXCLUDED.suspension_reason_code,
                            suspended_at = EXCLUDED.suspended_at,
                            suspended_by = NULL,
                            reactivated_at = EXCLUDED.reactivated_at,
                            reactivated_by = NULL,
                            updated_at = now(),
                            lock_version = provider_authorization.lock_version + 1
                        SQL,
                    [
                        'profileId' => $profile['id'],
                        'status' => $enabled ? 'ACTIVE' : 'SUSPENDED',
                        'canDeliver' => $enabled && $this->toBoolean($profile['can_deliver']),
                        'canTransport' => $enabled && $this->toBoolean($profile['can_transport_people']),
                        'reason' => $enabled ? null : 'ADMIN_ACCOUNT_DISABLED',
                        'suspendedAt' => $enabled ? null : (new \DateTimeImmutable())->format('Y-m-d H:i:sP'),
                        'reactivatedAt' => $enabled ? (new \DateTimeImmutable())->format('Y-m-d H:i:sP') : null,
                    ],
                    [
                        'canDeliver' => ParameterType::BOOLEAN,
                        'canTransport' => ParameterType::BOOLEAN,
                    ],
                );
            });
        } else {
            $this->db->executeStatement(
                'UPDATE user_account SET enabled = :enabled, token_version = token_version + 1 WHERE id = :id',
                ['enabled' => $enabled, 'id' => $id],
                ['enabled' => ParameterType::BOOLEAN, 'id' => ParameterType::INTEGER],
            );
        }

        return $this->find($id, $type);
    }

    public function delete(int $id, string $type): bool
    {
        $type = $this->type($type);
        if ($type === 'BO') {
            throw new \DomainException('Un compte BO ne peut pas être supprimé. Désactivez-le.');
        }
        if ($this->find($id, $type) === null) {
            return false;
        }

        try {
            return $this->db->delete('user_account', ['id' => $id]) > 0;
        } catch (ForeignKeyConstraintViolationException) {
            throw new \DomainException('Ce compte possède des données métier et ne peut pas être supprimé. Désactivez-le.');
        }
    }

    /** @return array<string, mixed>|null */
    private function find(int $id, string $type): ?array
    {
        foreach ($this->list($type) as $user) {
            if ($user['id'] === $id) {
                return $user;
            }
        }

        return null;
    }

    private function baseSelect(): string
    {
        return <<<'SQL'
            SELECT account.id, account.phone, account.name, account.email, account.verified, account.enabled,
                   account.created_at, back_office.enabled AS bo_enabled, back_office.last_login_at,
                   profile.id AS provider_profile_id, profile.validation_status,
                   profile.can_deliver, profile.can_transport_people,
                   COALESCE(STRING_AGG(DISTINCT role.role, ','), '') AS roles
            FROM user_account account
            LEFT JOIN back_office_account back_office ON back_office.user_id = account.id
            LEFT JOIN provider_profile profile ON profile.user_id = account.id
            LEFT JOIN user_account_role role ON role.user_id = account.id
            SQL;
    }

    private function groupBy(): string
    {
        return <<<'SQL'
            GROUP BY account.id, back_office.user_id, back_office.enabled, back_office.last_login_at,
                     profile.id, profile.validation_status, profile.can_deliver, profile.can_transport_people
            SQL;
    }

    /** @param array<string, mixed> $row
     *  @return array<string, mixed>
     */
    private function map(array $row, string $type): array
    {
        $roles = $row['roles'] === '' ? [] : explode(',', (string) $row['roles']);
        $providerStatus = $row['validation_status'] !== null ? (string) $row['validation_status'] : null;

        return [
            'id' => (int) $row['id'],
            'type' => $type,
            'name' => $row['name'] !== null ? (string) $row['name'] : null,
            'phone' => (string) $row['phone'],
            'email' => $row['email'] !== null ? (string) $row['email'] : null,
            'enabled' => match ($type) {
                'BO' => $this->toBoolean($row['bo_enabled']),
                'PRESTATAIRE' => $providerStatus === 'approved',
                'CLIENT' => $this->toBoolean($row['enabled']),
            },
            'verified' => $this->toBoolean($row['verified']),
            'roles' => $roles,
            'providerStatus' => $providerStatus,
            'canDeliver' => $this->toBoolean($row['can_deliver']),
            'canTransportPeople' => $this->toBoolean($row['can_transport_people']),
            'lastLoginAt' => $row['last_login_at'] !== null ? (string) $row['last_login_at'] : null,
            'createdAt' => (string) $row['created_at'],
        ];
    }

    private function replaceRoles(int $userId, array $roles): void
    {
        if ($roles === []) {
            throw new \InvalidArgumentException('Au moins un rôle BO est obligatoire.');
        }
        $this->db->delete('user_account_role', ['user_id' => $userId]);
        foreach ($roles as $role) {
            $this->db->insert('user_account_role', ['user_id' => $userId, 'role' => $role]);
        }
    }

    /** @return list<string> */
    private function roles(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException('roles doit être une liste.');
        }
        $roles = array_values(array_unique(array_filter($value, fn (mixed $role): bool => is_string($role) && in_array($role, self::BO_ROLES, true))));
        if (count($roles) !== count($value)) {
            throw new \InvalidArgumentException('Un rôle BO est invalide.');
        }

        return $roles;
    }

    private function type(mixed $value): string
    {
        $type = is_string($value) ? strtoupper(trim($value)) : '';
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('type doit être BO, PRESTATAIRE ou CLIENT.');
        }

        return $type;
    }

    private function phone(mixed $value): string
    {
        $phone = is_string($value) ? PhoneNumberNormalizer::normalize($value) : '';
        if ($phone === '') {
            throw new \InvalidArgumentException('Le téléphone est invalide.');
        }

        return $phone;
    }

    private function email(mixed $value): ?string
    {
        $email = $this->optionalText($value);
        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException("L'e-mail est invalide.");
        }

        return $email === null ? null : mb_strtolower($email);
    }

    private function optionalText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException('Une valeur texte est invalide.');
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function boolean(mixed $value): bool
    {
        if (!is_bool($value)) {
            throw new \InvalidArgumentException('Une valeur booléenne est invalide.');
        }

        return $value;
    }

    private function toBoolean(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }
}
