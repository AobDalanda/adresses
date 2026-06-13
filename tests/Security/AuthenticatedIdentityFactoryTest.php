<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\UserAccount;
use App\Repository\UserAccountRepository;
use App\Security\AuthenticatedIdentityFactory;
use App\Security\UserRoleProvider;
use PHPUnit\Framework\TestCase;

final class AuthenticatedIdentityFactoryTest extends TestCase
{
    public function testProviderRolesAreDerivedFromStoredAccountType(): void
    {
        $user = $this->user(42, 'provider');
        $users = $this->createMock(UserAccountRepository::class);
        $users->expects(self::once())->method('find')->with(42)->willReturn($user);
        $roles = $this->createMock(UserRoleProvider::class);
        $roles->expects(self::once())->method('rolesForUser')->with(42)->willReturn([]);

        $identity = (new AuthenticatedIdentityFactory($users, $roles))->fromMobileClaims([
            'uid' => 42,
            'typ' => 'mobile',
            'roles' => ['ROLE_ADMIN'],
        ]);

        self::assertNotNull($identity);
        self::assertSame(42, $identity->getUserId());
        self::assertSame(['ROLE_USER', 'ROLE_PROVIDER'], $identity->getRoles());
        self::assertSame('user:42', $identity->getUserIdentifier());
    }

    public function testAdminRoleIsDerivedFromStoredAccountType(): void
    {
        $users = $this->createMock(UserAccountRepository::class);
        $users->method('find')->willReturn($this->user(7, 'admin'));
        $roles = $this->createMock(UserRoleProvider::class);
        $roles->method('rolesForUser')->willReturn(['ROLE_ADMIN']);

        $identity = (new AuthenticatedIdentityFactory($users, $roles))->fromMobileClaims([
            'uid' => 7,
            'typ' => 'mobile',
        ]);

        self::assertNotNull($identity);
        self::assertSame(['ROLE_USER', 'ROLE_ADMIN'], $identity->getRoles());
    }

    public function testRefreshTokenCannotBecomeRequestIdentity(): void
    {
        $users = $this->createMock(UserAccountRepository::class);
        $users->expects(self::never())->method('find');
        $roles = $this->createMock(UserRoleProvider::class);
        $roles->expects(self::never())->method('rolesForUser');

        $identity = (new AuthenticatedIdentityFactory($users, $roles))->fromMobileClaims([
            'uid' => 42,
            'typ' => 'mobile_refresh',
        ]);

        self::assertNull($identity);
    }

    public function testAdministrativeRolesComeFromServerStorage(): void
    {
        $users = $this->createMock(UserAccountRepository::class);
        $users->method('find')->willReturn($this->user(12, 'client'));
        $roles = $this->createMock(UserRoleProvider::class);
        $roles->method('rolesForUser')->with(12)->willReturn([
            'ROLE_PROVIDER_APPROVER',
            'ROLE_PROVIDER_REVIEWER',
        ]);

        $identity = (new AuthenticatedIdentityFactory($users, $roles))->fromMobileClaims([
            'uid' => 12,
            'typ' => 'mobile',
        ]);

        self::assertNotNull($identity);
        self::assertSame([
            'ROLE_USER',
            'ROLE_PROVIDER_APPROVER',
            'ROLE_PROVIDER_REVIEWER',
        ], $identity->getRoles());
    }

    private function user(int $id, string $accountType): UserAccount
    {
        $user = (new UserAccount())
            ->setPhone('620000000')
            ->setAccountType($accountType);

        $property = new \ReflectionProperty(UserAccount::class, 'id');
        $property->setValue($user, $id);

        return $user;
    }
}
