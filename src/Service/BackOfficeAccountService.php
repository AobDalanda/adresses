<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;

final class BackOfficeAccountService
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function isEnabled(int $userId): bool
    {
        return (bool) $this->db->fetchOne(
            'SELECT EXISTS(SELECT 1 FROM back_office_account WHERE user_id = :userId AND enabled = TRUE)',
            ['userId' => $userId],
        );
    }

    public function findTokenVersion(int $userId): ?int
    {
        $version = $this->db->fetchOne(
            'SELECT token_version FROM back_office_account WHERE user_id = :userId AND enabled = TRUE',
            ['userId' => $userId],
        );

        return $version === false ? null : (int) $version;
    }

    public function rotateTokenVersion(int $userId): int
    {
        $version = $this->db->fetchOne(
            <<<'SQL'
                UPDATE back_office_account
                SET token_version = token_version + 1,
                    last_login_at = now(),
                    updated_at = now()
                WHERE user_id = :userId
                  AND enabled = TRUE
                RETURNING token_version
                SQL,
            ['userId' => $userId],
        );

        if ($version === false) {
            throw new \RuntimeException('Compte back-office introuvable ou desactive.');
        }

        return (int) $version;
    }
}
