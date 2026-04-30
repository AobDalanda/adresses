<?php

namespace App\Service;

use App\Util\PhoneNumberNormalizer;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

class UserAccountService
{
    public function __construct(private Connection $db)
    {
    }

    public function ensureUserAccount(string $phone, ?string $name = null): int
    {
        $phone = PhoneNumberNormalizer::normalize($phone);

        return (int) $this->db->fetchOne(
            "
            INSERT INTO user_account (phone, name, verified)
            VALUES (:phone, :name, true)
            ON CONFLICT (phone) DO UPDATE
                SET verified = true,
                    name = COALESCE(EXCLUDED.name, user_account.name)
            RETURNING id
            ",
            [
                'phone' => $phone,
                'name' => $name,
            ]
        );
    }

    /**
     * @return array{id: int, phone: string, name: string, verified: bool, createdAt: mixed}
     */
    public function upsertUserAccount(string $phone, ?string $name = null, bool $verified = true): array
    {
        $phone = PhoneNumberNormalizer::normalize($phone);
        $normalizedName = is_string($name) ? trim($name) : null;
        if ($normalizedName === '') {
            $normalizedName = null;
        }

        $user = $this->db->fetchAssociative(
            "
            INSERT INTO user_account (phone, name, verified)
            VALUES (:phone, :name, :verified)
            ON CONFLICT (phone) DO UPDATE
                SET verified = EXCLUDED.verified,
                    name = COALESCE(EXCLUDED.name, user_account.name)
            RETURNING id, phone, name, verified, created_at
            ",
            [
                'phone' => $phone,
                'name' => $normalizedName,
                'verified' => $verified,
            ]
        );

        return [
            'id' => (int) $user['id'],
            'phone' => (string) $user['phone'],
            'name' => (string) $user['name'],
            'verified' => (bool) $user['verified'],
            'createdAt' => $user['created_at'],
        ];
    }

    public function userExists(string $phone): bool
    {
        $phone = PhoneNumberNormalizer::normalize($phone);

        return (bool) $this->db->fetchOne(
            'SELECT EXISTS(SELECT 1 FROM user_account WHERE phone = :phone)',
            ['phone' => $phone]
        );
    }

    public function verifiedUserExists(string $phone): bool
    {
        $phone = PhoneNumberNormalizer::normalize($phone);

        return (bool) $this->db->fetchOne(
            'SELECT EXISTS(SELECT 1 FROM user_account WHERE phone = :phone AND verified = true)',
            ['phone' => $phone]
        );
    }

    public function createPendingRegistration(string $phone, string $fullName): void
    {
        $phone = PhoneNumberNormalizer::normalize($phone);

        $this->db->executeStatement(
            "
            INSERT INTO pending_user_registration (phone, full_name, status)
            VALUES (:phone, :fullName, 'PENDING')
            ON CONFLICT (phone) DO UPDATE
                SET full_name = EXCLUDED.full_name,
                    status = 'PENDING',
                    created_at = now(),
                    otp_verified_at = NULL
            ",
            [
                'phone' => $phone,
                'fullName' => $fullName,
            ]
        );
    }

    /**
     * @return array{id: int, phone: string, fullName: string}|null
     */
    public function findPendingRegistration(string $phone): ?array
    {
        $phone = PhoneNumberNormalizer::normalize($phone);

        $registration = $this->db->fetchAssociative(
            "
            SELECT id, phone, full_name
            FROM pending_user_registration
            WHERE phone = :phone
              AND status = 'PENDING'
            LIMIT 1
            ",
            ['phone' => $phone]
        );

        if (!$registration) {
            return null;
        }

        return [
            'id' => (int) $registration['id'],
            'phone' => (string) $registration['phone'],
            'fullName' => (string) $registration['full_name'],
        ];
    }

    public function markPendingRegistrationVerified(int $id): void
    {
        $this->db->executeStatement(
            "
            UPDATE pending_user_registration
            SET status = 'VERIFIED',
                otp_verified_at = now()
            WHERE id = :id
            ",
            ['id' => $id]
        );
    }

    /**
     * @return array{id: int, phone: string, name: string, verified: bool, createdAt: mixed}
     *
     * @throws UniqueConstraintViolationException
     */
    public function createUserAccount(string $phone, string $name, bool $verified = false): array
    {
        $phone = PhoneNumberNormalizer::normalize($phone);

        $user = $this->db->fetchAssociative(
            "
            INSERT INTO user_account (phone, name, verified)
            VALUES (:phone, :name, :verified)
            RETURNING id, phone, name, verified, created_at
            ",
            [
                'phone' => $phone,
                'name' => $name,
                'verified' => $verified,
            ]
        );

        return [
            'id' => (int) $user['id'],
            'phone' => (string) $user['phone'],
            'name' => (string) $user['name'],
            'verified' => (bool) $user['verified'],
            'createdAt' => $user['created_at'],
        ];
    }
}
