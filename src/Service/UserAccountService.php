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
        ?string $driverLicensePath = null
    ): int
    {
        $phone = PhoneNumberNormalizer::normalize($phone);

        return (int) $this->db->fetchOne(
            "
            INSERT INTO user_account (phone, name, verified, profile_photo_path, account_type, identity_document_path, driver_license_path)
            VALUES (:phone, :name, true, :profilePhotoPath, :accountType, :identityDocumentPath, :driverLicensePath)
            ON CONFLICT (phone) DO UPDATE
                SET verified = true,
                    name = COALESCE(EXCLUDED.name, user_account.name),
                    profile_photo_path = COALESCE(EXCLUDED.profile_photo_path, user_account.profile_photo_path),
                    account_type = COALESCE(EXCLUDED.account_type, user_account.account_type),
                    identity_document_path = COALESCE(EXCLUDED.identity_document_path, user_account.identity_document_path),
                    driver_license_path = COALESCE(EXCLUDED.driver_license_path, user_account.driver_license_path)
            RETURNING id
            ",
            [
                'phone' => $phone,
                'name' => $name,
                'profilePhotoPath' => $profilePhotoPath,
                'accountType' => $accountType,
                'identityDocumentPath' => $identityDocumentPath,
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
        ?string $driverLicensePath = null
    ): array
    {
        $phone = PhoneNumberNormalizer::normalize($phone);
        $normalizedName = is_string($name) ? trim($name) : null;
        if ($normalizedName === '') {
            $normalizedName = null;
        }

        $user = $this->db->fetchAssociative(
            "
            INSERT INTO user_account (phone, name, verified, profile_photo_path, account_type, identity_document_path, driver_license_path)
            VALUES (:phone, :name, :verified, :profilePhotoPath, :accountType, :identityDocumentPath, :driverLicensePath)
            ON CONFLICT (phone) DO UPDATE
                SET verified = EXCLUDED.verified,
                    name = COALESCE(EXCLUDED.name, user_account.name),
                    profile_photo_path = COALESCE(EXCLUDED.profile_photo_path, user_account.profile_photo_path),
                    account_type = COALESCE(EXCLUDED.account_type, user_account.account_type),
                    identity_document_path = COALESCE(EXCLUDED.identity_document_path, user_account.identity_document_path),
                    driver_license_path = COALESCE(EXCLUDED.driver_license_path, user_account.driver_license_path)
            RETURNING id, phone, name, verified, account_type, profile_photo_path, identity_document_path, driver_license_path, created_at
            ",
            [
                'phone' => $phone,
                'name' => $normalizedName,
                'verified' => $verified,
                'profilePhotoPath' => $profilePhotoPath,
                'accountType' => $accountType,
                'identityDocumentPath' => $identityDocumentPath,
                'driverLicensePath' => $driverLicensePath,
            ]
        );

        return [
            'id' => (int) $user['id'],
            'phone' => (string) $user['phone'],
            'name' => (string) $user['name'],
            'verified' => (bool) $user['verified'],
            'accountType' => (string) $user['account_type'],
            'profilePhotoPath' => isset($user['profile_photo_path']) ? (is_string($user['profile_photo_path']) && $user['profile_photo_path'] !== '' ? $user['profile_photo_path'] : null) : null,
            'identityDocumentPath' => isset($user['identity_document_path']) ? (is_string($user['identity_document_path']) && $user['identity_document_path'] !== '' ? $user['identity_document_path'] : null) : null,
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

    /**
     * @return array{id: int, phone: string, name: string, verified: bool, accountType: string, profilePhotoPath: ?string, identityDocumentPath: ?string, driverLicensePath: ?string, createdAt: mixed}|null
     */
    public function findVerifiedUserByPhone(string $phone): ?array
    {
        $phone = PhoneNumberNormalizer::normalize($phone);

        $user = $this->db->fetchAssociative(
            "
            SELECT id, phone, name, verified, account_type, profile_photo_path, identity_document_path, driver_license_path, created_at
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
        ?string $driverLicensePath = null
    ): void
    {
        $phone = PhoneNumberNormalizer::normalize($phone);

        $this->db->executeStatement(
            "
            INSERT INTO pending_user_registration (phone, full_name, profile_photo_path, account_type, identity_document_path, driver_license_path, status)
            VALUES (:phone, :fullName, :profilePhotoPath, :accountType, :identityDocumentPath, :driverLicensePath, 'PENDING')
            ON CONFLICT (phone) DO UPDATE
                SET full_name = EXCLUDED.full_name,
                    profile_photo_path = COALESCE(EXCLUDED.profile_photo_path, pending_user_registration.profile_photo_path),
                    account_type = COALESCE(EXCLUDED.account_type, pending_user_registration.account_type),
                    identity_document_path = COALESCE(EXCLUDED.identity_document_path, pending_user_registration.identity_document_path),
                    driver_license_path = COALESCE(EXCLUDED.driver_license_path, pending_user_registration.driver_license_path),
                    status = 'PENDING',
                    created_at = now(),
                    otp_verified_at = NULL
            ",
            [
                'phone' => $phone,
                'fullName' => $fullName,
                'profilePhotoPath' => $profilePhotoPath,
                'accountType' => $accountType,
                'identityDocumentPath' => $identityDocumentPath,
                'driverLicensePath' => $driverLicensePath,
            ]
        );
    }

    /**
     * @return array{id: int, phone: string, fullName: string, accountType: string, profilePhotoPath: ?string, identityDocumentPath: ?string, driverLicensePath: ?string}|null
     */
    public function findPendingRegistration(string $phone): ?array
    {
        $phone = PhoneNumberNormalizer::normalize($phone);

        $registration = $this->db->fetchAssociative(
            "
            SELECT id, phone, full_name, account_type, profile_photo_path, identity_document_path, driver_license_path
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
            'accountType' => (string) $registration['account_type'],
            'profilePhotoPath' => isset($registration['profile_photo_path']) ? (is_string($registration['profile_photo_path']) && $registration['profile_photo_path'] !== '' ? $registration['profile_photo_path'] : null) : null,
            'identityDocumentPath' => isset($registration['identity_document_path']) ? (is_string($registration['identity_document_path']) && $registration['identity_document_path'] !== '' ? $registration['identity_document_path'] : null) : null,
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
        ?string $driverLicensePath = null
    ): array
    {
        $phone = PhoneNumberNormalizer::normalize($phone);

        $user = $this->db->fetchAssociative(
            "
            INSERT INTO user_account (phone, name, verified, profile_photo_path, account_type, identity_document_path, driver_license_path)
            VALUES (:phone, :name, :verified, :profilePhotoPath, :accountType, :identityDocumentPath, :driverLicensePath)
            RETURNING id, phone, name, verified, account_type, profile_photo_path, identity_document_path, driver_license_path, created_at
            ",
            [
                'phone' => $phone,
                'name' => $name,
                'verified' => $verified,
                'profilePhotoPath' => $profilePhotoPath,
                'accountType' => $accountType,
                'identityDocumentPath' => $identityDocumentPath,
                'driverLicensePath' => $driverLicensePath,
            ]
        );

        return [
            'id' => (int) $user['id'],
            'phone' => (string) $user['phone'],
            'name' => (string) $user['name'],
            'verified' => (bool) $user['verified'],
            'accountType' => (string) $user['account_type'],
            'profilePhotoPath' => isset($user['profile_photo_path']) ? (is_string($user['profile_photo_path']) && $user['profile_photo_path'] !== '' ? $user['profile_photo_path'] : null) : null,
            'identityDocumentPath' => isset($user['identity_document_path']) ? (is_string($user['identity_document_path']) && $user['identity_document_path'] !== '' ? $user['identity_document_path'] : null) : null,
            'driverLicensePath' => isset($user['driver_license_path']) ? (is_string($user['driver_license_path']) && $user['driver_license_path'] !== '' ? $user['driver_license_path'] : null) : null,
            'createdAt' => $user['created_at'],
        ];
    }
}
