<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\UserAccount;
use App\Security\AuthenticatedIdentity;
use App\Security\AuthenticatedIdentityFactory;
use App\Security\RequestIdentityResolver;
use App\Service\JwtAuthService;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;

final class RequestIdentityResolverTest extends TestCase
{
    public function testSymfonyPrincipalHasPriorityOverLegacyDecode(): void
    {
        $identity = $this->identity();
        $security = $this->createMock(Security::class);
        $security->expects(self::once())->method('getUser')->willReturn($identity);
        $jwt = $this->createMock(JwtAuthService::class);
        $jwt->expects(self::never())->method('decodeFromRequest');
        $identities = $this->createMock(AuthenticatedIdentityFactory::class);

        $resolved = (new RequestIdentityResolver($security, $jwt, $identities))
            ->resolveMobile(new Request());

        self::assertSame($identity, $resolved);
    }

    public function testLegacyDecodeRemainsAvailableOutsideFirewall(): void
    {
        $request = new Request();
        $claims = ['uid' => 42, 'typ' => 'mobile', 'tv' => 3];
        $identity = $this->identity();

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn(null);
        $jwt = $this->createMock(JwtAuthService::class);
        $jwt->expects(self::once())->method('decodeFromRequest')->with($request)->willReturn($claims);
        $identities = $this->createMock(AuthenticatedIdentityFactory::class);
        $identities->expects(self::once())->method('fromMobileClaims')->with($claims)->willReturn($identity);

        $resolved = (new RequestIdentityResolver($security, $jwt, $identities))
            ->resolveMobile($request);

        self::assertSame($identity, $resolved);
    }

    private function identity(): AuthenticatedIdentity
    {
        $user = (new UserAccount())
            ->setPhone('620000000')
            ->setAccountType('provider');
        $property = new \ReflectionProperty(UserAccount::class, 'id');
        $property->setValue($user, 42);

        return new AuthenticatedIdentity(
            $user,
            'mobile',
            ['ROLE_USER', 'ROLE_PROVIDER'],
            ['uid' => 42, 'typ' => 'mobile', 'tv' => 3]
        );
    }
}
