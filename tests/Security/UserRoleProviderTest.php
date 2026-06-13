<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\UserRoleProvider;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class UserRoleProviderTest extends TestCase
{
    public function testRolesAreLoadedFromServerStorage(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())
            ->method('fetchFirstColumn')
            ->with(
                self::stringContains('FROM user_account_role'),
                ['userId' => 42]
            )
            ->willReturn([
                'ROLE_PROVIDER_APPROVER',
                'ROLE_PROVIDER_REVIEWER',
            ]);

        self::assertSame([
            'ROLE_PROVIDER_APPROVER',
            'ROLE_PROVIDER_REVIEWER',
        ], (new UserRoleProvider($db))->rolesForUser(42));
    }
}
