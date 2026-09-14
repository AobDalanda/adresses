<?php

declare(strict_types=1);

namespace App\Api\Controller;

use App\Security\Exception\SessionInvalidatedException;
use App\Service\MobileTokenRefreshService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class AuthRefreshTokenAction
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

        try {
            $tokens = $this->tokens->refresh($refreshToken);
        } catch (SessionInvalidatedException) {
            return new JsonResponse(['message' => 'SESSION_INVALIDATED'], 401);
        }

        if ($tokens === null) {
            return new JsonResponse(['message' => 'Refresh token invalide'], 401);
        }

        return new JsonResponse($tokens);
    }
}
