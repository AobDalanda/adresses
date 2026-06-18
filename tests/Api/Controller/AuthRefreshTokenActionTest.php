<?php

declare(strict_types=1);

namespace App\Tests\Api\Controller;

use App\Api\Controller\AuthRefreshTokenAction;
use App\Service\MobileTokenRefreshService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class AuthRefreshTokenActionTest extends TestCase
{
    public function testRefreshIssuesNewAccessAndRefreshTokens(): void
    {
        $tokens = $this->createMock(MobileTokenRefreshService::class);
        $tokens->expects(self::once())
            ->method('refresh')
            ->with('valid-refresh-token')
            ->willReturn([
                'token' => 'new-access-token',
                'refreshToken' => 'new-refresh-token',
            ]);

        $response = (new AuthRefreshTokenAction($tokens))->__invoke(
            new Request(content: '{"refreshToken":"valid-refresh-token"}')
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            '{"token":"new-access-token","refreshToken":"new-refresh-token"}',
            $response->getContent()
        );
    }
}
