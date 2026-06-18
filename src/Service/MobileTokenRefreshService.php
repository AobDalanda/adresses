<?php

namespace App\Service;

class MobileTokenRefreshService
{
    public function __construct(private JwtAuthService $jwt)
    {
    }

    /**
     * @return array{token: string, refreshToken: string}|null
     */
    public function refresh(string $refreshToken): ?array
    {
        $claims = $this->jwt->decodeToken(trim($refreshToken));
        if (
            !is_array($claims)
            || ($claims['typ'] ?? null) !== 'mobile_refresh'
            || !isset($claims['uid'], $claims['tv'], $claims['sub'])
        ) {
            return null;
        }

        $baseClaims = [
            'sub' => (string) $claims['sub'],
            'uid' => (int) $claims['uid'],
            'tv' => (int) $claims['tv'],
        ];

        return [
            'token' => $this->jwt->issueToken($baseClaims + ['typ' => 'mobile']),
            'refreshToken' => $this->jwt->issueToken(
                $baseClaims + ['typ' => 'mobile_refresh'],
                JwtAuthService::REFRESH_TOKEN_TTL_SECONDS
            ),
        ];
    }
}
