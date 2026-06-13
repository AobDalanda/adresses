<?php

declare(strict_types=1);

namespace App\Api\Controller;

use App\Security\ProviderAdministrationVoter;
use App\Service\ProviderProfileService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class AdminProviderStatusAction
{
    public function __construct(
        private readonly Security $security,
        private readonly ProviderProfileService $providers
    ) {
    }

    public function __invoke(int $id, Request $request): JsonResponse
    {
        if (
            !$this->security->isGranted(ProviderAdministrationVoter::DECIDE)
            && !$this->security->isGranted(ProviderAdministrationVoter::SUSPEND)
        ) {
            return new JsonResponse(['message' => 'Forbidden'], 403);
        }

        $payload = json_decode($request->getContent(), true);
        $status = is_array($payload) ? ($payload['validationStatus'] ?? null) : null;
        if (!is_string($status)) {
            return new JsonResponse(['message' => 'validationStatus est requis'], 400);
        }

        $normalizedStatus = strtolower(trim($status));
        $requiredPermission = $normalizedStatus === 'suspended'
            ? ProviderAdministrationVoter::SUSPEND
            : ProviderAdministrationVoter::DECIDE;
        if (!$this->security->isGranted($requiredPermission)) {
            return new JsonResponse(['message' => 'Forbidden'], 403);
        }

        try {
            $profile = $this->providers->updateStatus($id, $normalizedStatus);
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 400);
        }

        if ($profile === null) {
            return new JsonResponse(['message' => 'Profil prestataire introuvable'], 404);
        }

        return new JsonResponse(['providerProfile' => $profile]);
    }
}
