<?php

declare(strict_types=1);

namespace App\Api\Controller;

use App\Service\JwtAuthService;
use App\Service\ProviderProfileService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class AdminProviderDetailAction
{
    public function __construct(
        private readonly JwtAuthService $jwt,
        private readonly ProviderProfileService $providers
    ) {
    }

    public function __invoke(int $id, Request $request): JsonResponse
    {
        if (!$this->isAdmin($request)) {
            return new JsonResponse(['message' => 'Forbidden'], 403);
        }

        $profile = $this->providers->findById($id);
        if ($profile === null) {
            return new JsonResponse(['message' => 'Profil prestataire introuvable'], 404);
        }

        return new JsonResponse(['providerProfile' => $profile]);
    }

    private function isAdmin(Request $request): bool
    {
        $claims = $this->jwt->decodeFromRequest($request);
        $roles = is_array($claims) ? ($claims['roles'] ?? []) : [];

        return is_array($roles) && in_array('ROLE_ADMIN', $roles, true);
    }
}
