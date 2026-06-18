<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\JwtAuthService;
use App\Service\MobileTokenRefreshService;
use PHPUnit\Framework\TestCase;

final class MobileTokenRefreshServiceTest extends TestCase
{
    public function testRefreshIssuesMobileAccessAndRefreshTokens(): void
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
            ->willReturnCallback(
                static function (array $claims, ?int $ttl = null): string {
                    if ($claims['typ'] === 'mobile') {
                        self::assertNull($ttl);
                        self::assertSame(['sub' => '+224620000000', 'uid' => 42, 'tv' => 3, 'typ' => 'mobile'], $claims);

                        return 'new-access-token';
                    }

                    self::assertSame(JwtAuthService::REFRESH_TOKEN_TTL_SECONDS, $ttl);
                    self::assertSame(['sub' => '+224620000000', 'uid' => 42, 'tv' => 3, 'typ' => 'mobile_refresh'], $claims);

                    return 'new-refresh-token';
                }
            );

        self::assertSame(
            ['token' => 'new-access-token', 'refreshToken' => 'new-refresh-token'],
            (new MobileTokenRefreshService($jwt))->refresh('valid-refresh-token')
        );
    }

    public function testRefreshRejectsAccessTokenClaims(): void
    {
        $jwt = $this->createMock(JwtAuthService::class);
        $jwt->method('decodeToken')->willReturn([
            'sub' => '+224620000000',
            'typ' => 'mobile',
            'uid' => 42,
            'tv' => 3,
        ]);
        $jwt->expects(self::never())->method('issueToken');

        self::assertNull((new MobileTokenRefreshService($jwt))->refresh('access-token'));
    }
}
