<?php

namespace App\Api\Controller;

use App\Service\MobileTokenRefreshService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class AuthBiometricLoginAction
{
    public function __construct(private readonly MobileTokenRefreshService $tokens)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        $refreshToken = is_array($payload) ? ($payload['refreshToken'] ?? null) : null;
        if (!is_string($refreshToken) || trim($refreshToken) === '') {
            return new JsonResponse(['message' => 'refreshToken est requis'], 400);
        }

        $tokens = $this->tokens->refresh($refreshToken);
        if ($tokens === null) {
            return new JsonResponse(['message' => 'Refresh token invalide'], 401);
        }

        return new JsonResponse($tokens);
    }
}
