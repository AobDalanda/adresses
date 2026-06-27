<?php

namespace App\Service;

use App\Util\PhoneNumberNormalizer;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

class OtpService
{
    public const PURPOSE_MOBILE_AUTH = 'MOBILE_AUTH';
    public const PURPOSE_BACK_OFFICE_AUTH = 'BACK_OFFICE_AUTH';

    private const OTP_TTL_SECONDS = 300;
    private const RESEND_COOLDOWN_SECONDS = 30;

    public function __construct(
        private Connection $db,
        private WhatsAppOtpClient $whatsAppOtpClient,
        private LoggerInterface $logger
    ) {
    }

    public function requestOtp(string $phone, string $purpose = self::PURPOSE_MOBILE_AUTH): void
    {
        $this->assertPurpose($purpose);
        $phone = PhoneNumberNormalizer::normalize($phone);
        if ($this->hasRecentPendingOtp($phone, $purpose)) {
            return;
        }

        $otp = $this->generateOtp();
        $hash = password_hash($otp, PASSWORD_BCRYPT);
        $expiresAt = (new \DateTimeImmutable())->modify('+' . self::OTP_TTL_SECONDS . ' seconds');

        $this->db->executeStatement(
            "
            INSERT INTO otp_request (phone, otp_hash, status, channel, purpose, expires_at)
            VALUES (:phone, :hash, 'PENDING', 'WHATSAPP', :purpose, :expiresAt)
            ",
            [
                'phone' => $phone,
                'hash' => $hash,
                'purpose' => $purpose,
                'expiresAt' => $expiresAt->format('Y-m-d H:i:s'),
            ]
        );

        try {
            $this->whatsAppOtpClient->sendOtp($phone, $otp);
        } catch (\Throwable $exception) {
            $this->db->executeStatement(
                "
                UPDATE otp_request
                SET status = 'FAILED'
                WHERE phone = :phone
                  AND otp_hash = :hash
                  AND purpose = :purpose
                  AND status = 'PENDING'
                ",
                [
                    'phone' => $phone,
                    'hash' => $hash,
                    'purpose' => $purpose,
                ]
            );

            $this->logger->warning('OTP stored but WhatsApp delivery failed.', [
                'phone' => $phone,
                'purpose' => $purpose,
                'exception' => $exception,
            ]);

            throw $exception;
        }
    }

    public function verifyOtp(
        string $phone,
        string $otp,
        string $purpose = self::PURPOSE_MOBILE_AUTH
    ): bool
    {
        $this->assertPurpose($purpose);
        $phone = PhoneNumberNormalizer::normalize($phone);
        $row = $this->db->fetchAssociative(
            "
            SELECT id, otp_hash, expires_at
            FROM otp_request
            WHERE phone = :phone
              AND purpose = :purpose
              AND status = 'PENDING'
            ORDER BY created_at DESC
            LIMIT 1
            ",
            ['phone' => $phone, 'purpose' => $purpose]
        );

        if (!$row) {
            return false;
        }

        $expiresAt = new \DateTimeImmutable($row['expires_at']);
        if ($expiresAt < new \DateTimeImmutable()) {
            $this->markOtpStatus((int) $row['id'], 'EXPIRED');
            return false;
        }

        if (!password_verify($otp, $row['otp_hash'])) {
            return false;
        }

        $this->markOtpStatus((int) $row['id'], 'VERIFIED', new \DateTimeImmutable());

        return true;
    }

    private function markOtpStatus(int $id, string $status, ?\DateTimeImmutable $verifiedAt = null): void
    {
        $params = [
            'id' => $id,
            'status' => $status,
        ];

        $sql = "UPDATE otp_request SET status = :status";

        if ($verifiedAt) {
            $sql .= ", verified_at = :verifiedAt";
            $params['verifiedAt'] = $verifiedAt->format('Y-m-d H:i:s');
        }

        $sql .= " WHERE id = :id";

        $this->db->executeStatement($sql, $params);
    }

    private function generateOtp(): string
    {
        return (string) random_int(100000, 999999);
    }

    private function hasRecentPendingOtp(string $phone, string $purpose): bool
    {
        $createdAt = $this->db->fetchOne(
            "
            SELECT created_at
            FROM otp_request
            WHERE phone = :phone
              AND purpose = :purpose
              AND status = 'PENDING'
              AND expires_at > now()
            ORDER BY created_at DESC
            LIMIT 1
            ",
            ['phone' => $phone, 'purpose' => $purpose]
        );

        if (!$createdAt) {
            return false;
        }

        $lastRequestAt = new \DateTimeImmutable((string) $createdAt);
        $cooldownLimit = (new \DateTimeImmutable())->modify('-' . self::RESEND_COOLDOWN_SECONDS . ' seconds');

        return $lastRequestAt >= $cooldownLimit;
    }

    private function assertPurpose(string $purpose): void
    {
        if (!in_array($purpose, [self::PURPOSE_MOBILE_AUTH, self::PURPOSE_BACK_OFFICE_AUTH], true)) {
            throw new \InvalidArgumentException('Contexte OTP invalide.');
        }
    }
}
