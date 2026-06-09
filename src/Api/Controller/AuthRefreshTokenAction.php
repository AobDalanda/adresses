<?php

declare(strict_types=1);

namespace App\Api\Controller;

use App\Service\JwtAuthService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class AuthRefreshTokenAction
{
    public function __construct(private readonly JwtAuthService $jwt)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        $refreshToken = is_array($payload) ? ($payload['refreshToken'] ?? null) : null;
        if (!is_string($refreshToken) || trim($refreshToken) === '') {
            return new JsonResponse(['message' => 'refreshToken est requis'], 400);
        }

        $claims = $this->jwt->decodeToken(trim($refreshToken));
        if (
            !is_array($claims)
            || ($claims['typ'] ?? null) !== 'mobile_refresh'
            || !isset($claims['uid'], $claims['tv'], $claims['sub'])
        ) {
            return new JsonResponse(['message' => 'Refresh token invalide'], 401);
        }

        $baseClaims = [
            'sub' => (string) $claims['sub'],
            'uid' => (int) $claims['uid'],
            'tv' => (int) $claims['tv'],
        ];

        return new JsonResponse([
            'token' => $this->jwt->issueToken($baseClaims + ['typ' => 'mobile']),
            'refreshToken' => $this->jwt->issueToken(
                $baseClaims + ['typ' => 'mobile_refresh'],
                JwtAuthService::REFRESH_TOKEN_TTL_SECONDS
            ),
        ]);
    }
}
