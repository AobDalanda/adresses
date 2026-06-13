<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\UserAccount;
use App\Security\AuthenticatedIdentity;
use App\Security\AuthenticatedIdentityFactory;
use App\Security\MobileJwtAuthenticator;
use App\Service\JwtAuthService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;

final class MobileJwtAuthenticatorTest extends TestCase
{
    public function testValidMobileTokenCreatesSymfonyIdentity(): void
    {
        $request = Request::create('/api/v1/provider/profile');
        $request->headers->set('Authorization', 'Bearer valid-token');

        $claims = ['uid' => 42, 'typ' => 'mobile', 'tv' => 3];
        $jwt = $this->createMock(JwtAuthService::class);
        $jwt->expects(self::once())->method('decodeFromRequest')->with($request)->willReturn($claims);

        $identity = new AuthenticatedIdentity(
            $this->user(42),
            'mobile',
            ['ROLE_USER', 'ROLE_PROVIDER'],
            $claims
        );
        $identities = $this->createMock(AuthenticatedIdentityFactory::class);
        $identities->expects(self::once())->method('fromMobileClaims')->with($claims)->willReturn($identity);

        $authenticator = new MobileJwtAuthenticator($jwt, $identities);
        $passport = $authenticator->authenticate($request);

        self::assertTrue($authenticator->supports($request));
        self::assertSame($identity, $passport->getUser());
    }

    public function testInvalidTokenFailsWithoutOwningTheV1Response(): void
    {
        $request = Request::create('/api/v1/provider/profile');
        $request->headers->set('Authorization', 'Bearer invalid-token');

        $jwt = $this->createMock(JwtAuthService::class);
        $jwt->method('decodeFromRequest')->willReturn(null);
        $identities = $this->createMock(AuthenticatedIdentityFactory::class);

        $authenticator = new MobileJwtAuthenticator($jwt, $identities);

        try {
            $authenticator->authenticate($request);
            self::fail('Invalid JWT should fail authentication.');
        } catch (BadCredentialsException $exception) {
            self::assertNull($authenticator->onAuthenticationFailure($request, $exception));
        }
    }

    private function user(int $id): UserAccount
    {
        $user = (new UserAccount())
            ->setPhone('620000000')
            ->setAccountType('provider');

        $property = new \ReflectionProperty(UserAccount::class, 'id');
        $property->setValue($user, $id);

        return $user;
    }
}
