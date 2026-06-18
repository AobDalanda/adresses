<?php

declare(strict_types=1);

namespace App\Tests\Api\Controller;

use App\Api\Controller\AuthBiometricLoginAction;
use App\Service\MobileTokenRefreshService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class AuthBiometricLoginActionTest extends TestCase
{
    public function testBiometricLoginIssuesNewAccessAndRefreshTokens(): void
    {
        $tokens = $this->createMock(MobileTokenRefreshService::class);
        $tokens->expects(self::once())
            ->method('refresh')
            ->with('valid-refresh-token')
            ->willReturn([
                'token' => 'new-access-token',
                'refreshToken' => 'new-refresh-token',
            ]);

        $response = (new AuthBiometricLoginAction($tokens))->__invoke(
            new Request(content: '{"refreshToken":"valid-refresh-token"}')
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            '{"token":"new-access-token","refreshToken":"new-refresh-token"}',
            $response->getContent()
        );
    }

    public function testBiometricLoginRequiresRefreshToken(): void
    {
        $tokens = $this->createMock(MobileTokenRefreshService::class);
        $tokens->expects(self::never())->method('refresh');

        $response = (new AuthBiometricLoginAction($tokens))->__invoke(
            new Request(content: '{}')
        );

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('{"message":"refreshToken est requis"}', $response->getContent());
    }

    public function testBiometricLoginRejectsInvalidRefreshToken(): void
    {
        $tokens = $this->createMock(MobileTokenRefreshService::class);
        $tokens->expects(self::once())
            ->method('refresh')
            ->with('invalid-refresh-token')
            ->willReturn(null);

        $response = (new AuthBiometricLoginAction($tokens))->__invoke(
            new Request(content: '{"refreshToken":"invalid-refresh-token"}')
        );

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('{"message":"Refresh token invalide"}', $response->getContent());
    }
}
