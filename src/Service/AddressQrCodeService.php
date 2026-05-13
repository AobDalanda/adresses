<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

final class AddressQrCodeService
{
    public function __construct(
        private Connection $db,
        private QrTokenGenerator $tokenGenerator,
        private int $defaultMaxScans = 100,
        private string $defaultExpiresIn = '365 days'
    ) {
    }

    /**
     * @return array{token: string, expires_at: string, max_scans: int}
     */
    public function generateForUser(int $userId, int $addressId): array
    {
        $ownsAddress = (bool) $this->db->fetchOne(
            '
            SELECT EXISTS(
                SELECT 1
                FROM user_address
                WHERE user_id = :userId
                  AND address_id = :addressId
            )
            ',
            [
                'userId' => $userId,
                'addressId' => $addressId,
            ]
        );

        if (!$ownsAddress) {
            throw new \DomainException('Cette adresse ne vous appartient pas');
        }

        $expiresAt = (new \DateTimeImmutable())->modify(sprintf('+%s', trim($this->defaultExpiresIn)));
        if (!$expiresAt instanceof \DateTimeImmutable) {
            throw new \RuntimeException('Impossible de calculer l’expiration du QR Code');
        }

        $this->db->beginTransaction();

        try {
            do {
                $token = $this->tokenGenerator->generate();
                $exists = (bool) $this->db->fetchOne(
                    'SELECT EXISTS(SELECT 1 FROM address_qrcodes WHERE token = :token)',
                    ['token' => $token]
                );
            } while ($exists);

            $this->db->insert('address_qrcodes', [
                'address_id' => $addressId,
                'token' => $token,
                'is_active' => true,
                'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
                'max_scans' => $this->defaultMaxScans,
                'current_scans' => 0,
                'allowed_user_id' => null,
                'created_by' => $userId,
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'revoked_at' => null,
            ]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return [
            'token' => $token,
            'expires_at' => $expiresAt->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM),
            'max_scans' => $this->defaultMaxScans,
        ];
    }
}
