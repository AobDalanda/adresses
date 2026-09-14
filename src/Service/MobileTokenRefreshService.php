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
            || !isset($claims['uid'], $claims['sub'])
        ) {
            return null;
        }

        $tokenVersion = $claims['tv'] ?? $claims['sessionVersion'] ?? null;
        $deviceId = $claims['did'] ?? $claims['deviceId'] ?? null;
        if ($tokenVersion === null || !is_string($deviceId)) {
            return null;
        }

        $baseClaims = [
            'sub' => (string) $claims['sub'],
            'uid' => (int) $claims['uid'],
            'tv' => (int) $tokenVersion,
            'sessionVersion' => (int) $tokenVersion,
            'did' => $deviceId,
            'deviceId' => $deviceId,
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
