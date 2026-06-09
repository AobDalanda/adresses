<?php

declare(strict_types=1);

namespace App\Tests\Api\Controller;

use App\Api\Controller\AuthRefreshTokenAction;
use App\Service\JwtAuthService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class AuthRefreshTokenActionTest extends TestCase
{
    public function testRefreshIssuesNewAccessAndRefreshTokens(): void
    {
        $jwt = $this->createMock(JwtAuthService::class);
        $jwt->expects(self::once())
            ->method('decodeToken')
            ->with('valid-refresh-token')
            ->willReturn([
                'sub' => '+224620000000',
                'typ' => 'mobile_refresh',
                'uid' => 42,
                'tv' => 3,
            ]);
        $jwt->expects(self::exactly(2))
            ->method('issueToken')
            ->willReturnOnConsecutiveCalls('new-access-token', 'new-refresh-token');

        $response = (new AuthRefreshTokenAction($jwt))->__invoke(
            new Request(content: '{"refreshToken":"valid-refresh-token"}')
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            '{"token":"new-access-token","refreshToken":"new-refresh-token"}',
            $response->getContent()
        );
    }
}
