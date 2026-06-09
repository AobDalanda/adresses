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

    public function ensureUserAccount(
        string $phone,
        ?string $name = null,
        ?string $profilePhotoPath = null,
        string $accountType = 'client',
        ?string $identityDocumentPath = null,
        ?string $driverLicensePath = null,
        ?string $email = null,
        ?string $identityDocumentNumber = null
    ): int
    {
        $phone = PhoneNumberNormalizer::normalize($phone);
        $normalizedEmail = $this->normalizeEmail($email);
        $normalizedIdentityDocumentNumber = $this->normalizeIdentityDocumentNumber($identityDocumentNumber);

        return (int) $this->db->fetchOne(
            "
            INSERT INTO user_account (phone, name, email, verified, profile_photo_path, account_type, identity_document_path, identity_document_number, driver_license_path)
            VALUES (:phone, :name, :email, true, :profilePhotoPath, :accountType, :identityDocumentPath, :identityDocumentNumber, :driverLicensePath)
            ON CONFLICT (phone) DO UPDATE
                SET verified = true,
                    name = COALESCE(EXCLUDED.name, user_account.name),
                    email = COALESCE(EXCLUDED.email, user_account.email),
                    profile_photo_path = COALESCE(EXCLUDED.profile_photo_path, user_account.profile_photo_path),
                    account_type = CASE
                        WHEN user_account.account_type IN ('provider', 'admin') AND EXCLUDED.account_type = 'client'
                            THEN user_account.account_type
                        ELSE COALESCE(EXCLUDED.account_type, user_account.account_type)
                    END,
                    identity_document_path = COALESCE(EXCLUDED.identity_document_path, user_account.identity_document_path),
                    identity_document_number = COALESCE(EXCLUDED.identity_document_number, user_account.identity_document_number),
                    driver_license_path = COALESCE(EXCLUDED.driver_license_path, user_account.driver_license_path)
            RETURNING id
            ",
            [
                'phone' => $phone,
                'name' => $name,
                'email' => $normalizedEmail,
                'profilePhotoPath' => $profilePhotoPath,
                'accountType' => $accountType,
                'identityDocumentPath' => $identityDocumentPath,
                'identityDocumentNumber' => $normalizedIdentityDocumentNumber,
                'driverLicensePath' => $driverLicensePath,
            ]
        );
    }

    /**
     * @return array{id: int, phone: string, name: string, verified: bool, accountType: string, profilePhotoPath: ?string, identityDocumentPath: ?string, driverLicensePath: ?string, createdAt: mixed}
     */
    public function upsertUserAccount(
        string $phone,
        ?string $name = null,
        bool $verified = true,
        ?string $profilePhotoPath = null,
        string $accountType = 'client',
        ?string $identityDocumentPath = null,
        ?string $driverLicensePath = null,
        ?string $email = null,
        ?string $identityDocumentNumber = null
    ): array
    {
        $phone = PhoneNumberNormalizer::normalize($phone);
        $normalizedName = is_string($name) ? trim($name) : null;
        if ($normalizedName === '') {
            $normalizedName = null;
        }
        $normalizedEmail = $this->normalizeEmail($email);
        $normalizedIdentityDocumentNumber = $this->normalizeIdentityDocumentNumber($identityDocumentNumber);

        $user = $this->db->fetchAssociative(
            "
            INSERT INTO user_account (phone, name, email, verified, profile_photo_path, account_type, identity_document_path, identity_document_number, driver_license_path)
            VALUES (:phone, :name, :email, :verified, :profilePhotoPath, :accountType, :identityDocumentPath, :identityDocumentNumber, :driverLicensePath)
            ON CONFLICT (phone) DO UPDATE
                SET verified = EXCLUDED.verified,
                    name = COALESCE(EXCLUDED.name, user_account.name),
                    email = COALESCE(EXCLUDED.email, user_account.email),
                    profile_photo_path = COALESCE(EXCLUDED.profile_photo_path, user_account.profile_photo_path),
                    account_type = CASE
                        WHEN user_account.account_type IN ('provider', 'admin') AND EXCLUDED.account_type = 'client'
                            THEN user_account.account_type
                        ELSE COALESCE(EXCLUDED.account_type, user_account.account_type)
                    END,
                    identity_document_path = COALESCE(EXCLUDED.identity_document_path, user_account.identity_document_path),
                    identity_document_number = COALESCE(EXCLUDED.identity_document_number, user_account.identity_document_number),
                    driver_license_path = COALESCE(EXCLUDED.driver_license_path, user_account.driver_license_path)
            RETURNING id, phone, name, email, verified, account_type, profile_photo_path, identity_document_path, identity_document_number, driver_license_path, created_at
            ",
            [
                'phone' => $phone,
                'name' => $normalizedName,
                'email' => $normalizedEmail,
                'verified' => $verified,
                'profilePhotoPath' => $profilePhotoPath,
                'accountType' => $accountType,
                'identityDocumentPath' => $identityDocumentPath,
                'identityDocumentNumber' => $normalizedIdentityDocumentNumber,
                'driverLicensePath' => $driverLicensePath,
            ]
        );

        return [
            'id' => (int) $user['id'],
            'phone' => (string) $user['phone'],
            'name' => (string) $user['name'],
            'email' => isset($user['email']) ? (is_string($user['email']) && $user['email'] !== '' ? $user['email'] : null) : null,
            'verified' => (bool) $user['verified'],
            'accountType' => (string) $user['account_type'],
            'profilePhotoPath' => isset($user['profile_photo_path']) ? (is_string($user['profile_photo_path']) && $user['profile_photo_path'] !== '' ? $user['profile_photo_path'] : null) : null,
            'identityDocumentPath' => isset($user['identity_document_path']) ? (is_string($user['identity_document_path']) && $user['identity_document_path'] !== '' ? $user['identity_document_path'] : null) : null,
            'identityDocumentNumber' => isset($user['identity_document_number']) ? (is_string($user['identity_document_number']) && $user['identity_document_number'] !== '' ? $user['identity_document_number'] : null) : null,
            'driverLicensePath' => isset($user['driver_license_path']) ? (is_string($user['driver_license_path']) && $user['driver_license_path'] !== '' ? $user['driver_license_path'] : null) : null,
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

    public function findTokenVersionById(int $userId): ?int
    {
        $tokenVersion = $this->db->fetchOne(
            'SELECT token_version FROM user_account WHERE id = :id LIMIT 1',
            ['id' => $userId]
        );

        if ($tokenVersion === false) {
            return null;
        }

        return (int) $tokenVersion;
    }

    public function rotateTokenVersion(int $userId): int
    {
        $tokenVersion = $this->db->fetchOne(
            '
            UPDATE user_account
            SET token_version = token_version + 1
            WHERE id = :id
            RETURNING token_version
            ',
            ['id' => $userId]
        );

        if ($tokenVersion === false) {
            throw new \RuntimeException(sprintf('Utilisateur %d introuvable.', $userId));
        }

        return (int) $tokenVersion;
    }

    /**
     * @return array{id: int, phone: string, name: string, verified: bool, accountType: string, profilePhotoPath: ?string, identityDocumentPath: ?string, driverLicensePath: ?string, createdAt: mixed}|null
     */
    public function findVerifiedUserByPhone(string $phone): ?array
    {
        $phone = PhoneNumberNormalizer::normalize($phone);

        $user = $this->db->fetchAssociative(
            "
            SELECT id, phone, name, email, verified, account_type, profile_photo_path, identity_document_path, driver_license_path, created_at
            FROM user_account
            WHERE phone = :phone
              AND verified = true
            LIMIT 1
            ",
            ['phone' => $phone]
        );

        if (!$user) {
            return null;
        }

        return [
            'id' => (int) $user['id'],
            'phone' => (string) $user['phone'],
            'name' => (string) $user['name'],
            'email' => isset($user['email']) ? (is_string($user['email']) && $user['email'] !== '' ? $user['email'] : null) : null,
            'verified' => (bool) $user['verified'],
            'accountType' => (string) $user['account_type'],
            'profilePhotoPath' => isset($user['profile_photo_path']) ? (is_string($user['profile_photo_path']) && $user['profile_photo_path'] !== '' ? $user['profile_photo_path'] : null) : null,
            'identityDocumentPath' => isset($user['identity_document_path']) ? (is_string($user['identity_document_path']) && $user['identity_document_path'] !== '' ? $user['identity_document_path'] : null) : null,
            'driverLicensePath' => isset($user['driver_license_path']) ? (is_string($user['driver_license_path']) && $user['driver_license_path'] !== '' ? $user['driver_license_path'] : null) : null,
            'createdAt' => $user['created_at'],
        ];
    }

    public function createPendingRegistration(
        string $phone,
        string $fullName,
        ?string $profilePhotoPath = null,
        string $accountType = 'client',
        ?string $identityDocumentPath = null,
        ?string $driverLicensePath = null,
        ?string $email = null,
        ?string $identityDocumentNumber = null
    ): void
    {
        $phone = PhoneNumberNormalizer::normalize($phone);
        $normalizedEmail = $this->normalizeEmail($email);
        $normalizedIdentityDocumentNumber = $this->normalizeIdentityDocumentNumber($identityDocumentNumber);

        $this->db->executeStatement(
            "
            INSERT INTO pending_user_registration (phone, full_name, email, profile_photo_path, account_type, identity_document_path, identity_document_number, driver_license_path, status)
            VALUES (:phone, :fullName, :email, :profilePhotoPath, :accountType, :identityDocumentPath, :identityDocumentNumber, :driverLicensePath, 'PENDING')
            ON CONFLICT (phone) DO UPDATE
                SET full_name = EXCLUDED.full_name,
                    email = COALESCE(EXCLUDED.email, pending_user_registration.email),
                    profile_photo_path = COALESCE(EXCLUDED.profile_photo_path, pending_user_registration.profile_photo_path),
                    account_type = COALESCE(EXCLUDED.account_type, pending_user_registration.account_type),
                    identity_document_path = COALESCE(EXCLUDED.identity_document_path, pending_user_registration.identity_document_path),
                    identity_document_number = COALESCE(EXCLUDED.identity_document_number, pending_user_registration.identity_document_number),
                    driver_license_path = COALESCE(EXCLUDED.driver_license_path, pending_user_registration.driver_license_path),
                    status = 'PENDING',
                    created_at = now(),
                    otp_verified_at = NULL
            ",
            [
                'phone' => $phone,
                'fullName' => $fullName,
                'email' => $normalizedEmail,
                'profilePhotoPath' => $profilePhotoPath,
                'accountType' => $accountType,
                'identityDocumentPath' => $identityDocumentPath,
                'identityDocumentNumber' => $normalizedIdentityDocumentNumber,
                'driverLicensePath' => $driverLicensePath,
            ]
        );
    }

    /**
     * @return array{id: int, phone: string, fullName: string, email: ?string, accountType: string, profilePhotoPath: ?string, identityDocumentPath: ?string, identityDocumentNumber: ?string, driverLicensePath: ?string}|null
     */
    public function findPendingRegistration(string $phone): ?array
    {
        $phone = PhoneNumberNormalizer::normalize($phone);

        $registration = $this->db->fetchAssociative(
            "
            SELECT id, phone, full_name, email, account_type, profile_photo_path, identity_document_path, identity_document_number, driver_license_path
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
            'email' => isset($registration['email']) ? (is_string($registration['email']) && $registration['email'] !== '' ? $registration['email'] : null) : null,
            'accountType' => (string) $registration['account_type'],
            'profilePhotoPath' => isset($registration['profile_photo_path']) ? (is_string($registration['profile_photo_path']) && $registration['profile_photo_path'] !== '' ? $registration['profile_photo_path'] : null) : null,
            'identityDocumentPath' => isset($registration['identity_document_path']) ? (is_string($registration['identity_document_path']) && $registration['identity_document_path'] !== '' ? $registration['identity_document_path'] : null) : null,
            'identityDocumentNumber' => isset($registration['identity_document_number']) ? (is_string($registration['identity_document_number']) && $registration['identity_document_number'] !== '' ? $registration['identity_document_number'] : null) : null,
            'driverLicensePath' => isset($registration['driver_license_path']) ? (is_string($registration['driver_license_path']) && $registration['driver_license_path'] !== '' ? $registration['driver_license_path'] : null) : null,
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
     * @return array{id: int, phone: string, name: string, verified: bool, accountType: string, profilePhotoPath: ?string, identityDocumentPath: ?string, driverLicensePath: ?string, createdAt: mixed}
     *
     * @throws UniqueConstraintViolationException
     */
    public function createUserAccount(
        string $phone,
        string $name,
        bool $verified = false,
        ?string $profilePhotoPath = null,
        string $accountType = 'client',
        ?string $identityDocumentPath = null,
        ?string $driverLicensePath = null,
        ?string $email = null,
        ?string $identityDocumentNumber = null
    ): array
    {
        $phone = PhoneNumberNormalizer::normalize($phone);
        $normalizedEmail = $this->normalizeEmail($email);
        $normalizedIdentityDocumentNumber = $this->normalizeIdentityDocumentNumber($identityDocumentNumber);

        $user = $this->db->fetchAssociative(
            "
            INSERT INTO user_account (phone, name, email, verified, profile_photo_path, account_type, identity_document_path, identity_document_number, driver_license_path)
            VALUES (:phone, :name, :email, :verified, :profilePhotoPath, :accountType, :identityDocumentPath, :identityDocumentNumber, :driverLicensePath)
            RETURNING id, phone, name, email, verified, account_type, profile_photo_path, identity_document_path, identity_document_number, driver_license_path, created_at
            ",
            [
                'phone' => $phone,
                'name' => $name,
                'email' => $normalizedEmail,
                'verified' => $verified,
                'profilePhotoPath' => $profilePhotoPath,
                'accountType' => $accountType,
                'identityDocumentPath' => $identityDocumentPath,
                'identityDocumentNumber' => $normalizedIdentityDocumentNumber,
                'driverLicensePath' => $driverLicensePath,
            ]
        );

        return [
            'id' => (int) $user['id'],
            'phone' => (string) $user['phone'],
            'name' => (string) $user['name'],
            'email' => isset($user['email']) ? (is_string($user['email']) && $user['email'] !== '' ? $user['email'] : null) : null,
            'verified' => (bool) $user['verified'],
            'accountType' => (string) $user['account_type'],
            'profilePhotoPath' => isset($user['profile_photo_path']) ? (is_string($user['profile_photo_path']) && $user['profile_photo_path'] !== '' ? $user['profile_photo_path'] : null) : null,
            'identityDocumentPath' => isset($user['identity_document_path']) ? (is_string($user['identity_document_path']) && $user['identity_document_path'] !== '' ? $user['identity_document_path'] : null) : null,
            'identityDocumentNumber' => isset($user['identity_document_number']) ? (is_string($user['identity_document_number']) && $user['identity_document_number'] !== '' ? $user['identity_document_number'] : null) : null,
            'driverLicensePath' => isset($user['driver_license_path']) ? (is_string($user['driver_license_path']) && $user['driver_license_path'] !== '' ? $user['driver_license_path'] : null) : null,
            'createdAt' => $user['created_at'],
        ];
    }

    /**
     * @return array{id: int, phone: string, name: ?string, email: ?string, verified: bool, accountType: string, profilePhotoPath: ?string, identityDocumentPath: ?string, driverLicensePath: ?string, createdAt: mixed}|null
     */
    public function updateCurrentUserProfile(int $userId, ?string $name, mixed $email, ?string $profilePhotoPath = null): ?array
    {
        $normalizedName = $this->normalizeName($name);
        $emailWasProvided = $email !== null || is_string($email);
        $normalizedEmail = $this->normalizeEmail(is_string($email) ? $email : null);

        $user = $this->db->fetchAssociative(
            '
            UPDATE user_account
            SET name = COALESCE(:name, name),
                email = CASE
                    WHEN :emailProvided = true THEN :email
                    ELSE email
                END,
                profile_photo_path = COALESCE(:profilePhotoPath, profile_photo_path)
            WHERE id = :id
            RETURNING id, phone, name, email, verified, account_type, profile_photo_path, identity_document_path, driver_license_path, created_at
            ',
            [
                'id' => $userId,
                'name' => $normalizedName,
                'email' => $normalizedEmail,
                'emailProvided' => $emailWasProvided,
                'profilePhotoPath' => $profilePhotoPath,
            ]
        );

        if ($user === false) {
            return null;
        }

        return [
            'id' => (int) $user['id'],
            'phone' => (string) $user['phone'],
            'name' => isset($user['name']) && is_string($user['name']) && $user['name'] !== '' ? $user['name'] : null,
            'email' => isset($user['email']) && is_string($user['email']) && $user['email'] !== '' ? $user['email'] : null,
            'verified' => (bool) $user['verified'],
            'accountType' => (string) $user['account_type'],
            'profilePhotoPath' => isset($user['profile_photo_path']) ? (is_string($user['profile_photo_path']) && $user['profile_photo_path'] !== '' ? $user['profile_photo_path'] : null) : null,
            'identityDocumentPath' => isset($user['identity_document_path']) ? (is_string($user['identity_document_path']) && $user['identity_document_path'] !== '' ? $user['identity_document_path'] : null) : null,
            'driverLicensePath' => isset($user['driver_license_path']) ? (is_string($user['driver_license_path']) && $user['driver_license_path'] !== '' ? $user['driver_license_path'] : null) : null,
            'createdAt' => $user['created_at'],
        ];
    }

    private function normalizeName(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        $normalized = trim(preg_replace('/\s+/', ' ', $name) ?? $name);

        return $normalized === '' ? null : $normalized;
    }

    private function normalizeEmail(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        $normalized = strtolower(trim($email));

        return $normalized === '' ? null : $normalized;
    }

    private function normalizeIdentityDocumentNumber(?string $identityDocumentNumber): ?string
    {
        if ($identityDocumentNumber === null) {
            return null;
        }

        $normalized = trim(preg_replace('/\s+/', ' ', $identityDocumentNumber) ?? $identityDocumentNumber);

        return $normalized === '' ? null : $normalized;
    }
}
