<?php

declare(strict_types=1);

namespace App\Api\Controller;

use App\Service\JwtAuthService;
use App\Service\ProviderProfileService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class ProviderProfileGetAction
{
    public function __construct(
        private readonly JwtAuthService $jwt,
        private readonly ProviderProfileService $providers
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $claims = $this->jwt->decodeFromRequest($request);
        if (!is_array($claims) || ($claims['typ'] ?? null) !== 'mobile' || !isset($claims['uid'])) {
            return new JsonResponse(['message' => 'Unauthorized'], 401);
        }

        $profile = $this->providers->findByUserId((int) $claims['uid']);
        if ($profile === null) {
            return new JsonResponse(['message' => 'Profil prestataire introuvable'], 404);
        }

        return new JsonResponse(['providerProfile' => $profile]);
    }
}
