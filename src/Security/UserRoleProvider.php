<?php

declare(strict_types=1);

namespace App\Security;

use Doctrine\DBAL\Connection;

class UserRoleProvider
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @return list<string>
     */
    public function rolesForUser(int $userId): array
    {
        $roles = $this->db->fetchFirstColumn(
            <<<'SQL'
                SELECT role
                FROM user_account_role
                WHERE user_id = :userId
                ORDER BY role
                SQL,
            ['userId' => $userId]
        );

        return array_values(array_filter($roles, 'is_string'));
    }
}
