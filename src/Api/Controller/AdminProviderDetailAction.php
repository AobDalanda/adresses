<?php

declare(strict_types=1);

namespace App\Api\Controller;

use App\Security\ProviderAdministrationVoter;
use App\Service\ProviderProfileService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class AdminProviderDetailAction
{
    public function __construct(
        private readonly Security $security,
        private readonly ProviderProfileService $providers
    ) {
    }

    public function __invoke(int $id, Request $request): JsonResponse
    {
        if (!$this->security->isGranted(ProviderAdministrationVoter::VIEW)) {
            return new JsonResponse(['message' => 'Forbidden'], 403);
        }

        $profile = $this->providers->findById($id);
        if ($profile === null) {
            return new JsonResponse(['message' => 'Profil prestataire introuvable'], 404);
        }

        return new JsonResponse(['providerProfile' => $profile]);
    }
}
