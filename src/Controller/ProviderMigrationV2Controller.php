<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\Controller\AuthenticatedUserResolver;
use App\Service\ProviderApiRolloutPolicy;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class ProviderMigrationV2Controller
{
    public function __construct(
        private readonly AuthenticatedUserResolver $users,
        private readonly ProviderApiRolloutPolicy $rollout,
    ) {
    }

    #[Route('/api/v2/provider/migration-config', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->users->requireMobileUser($request);
        if ($user === null) {
            return new JsonResponse(['message' => 'Unauthorized'], 401);
        }

        return new JsonResponse([
            'providerApiVersion' => $this->rollout->shouldUseV2((int) $user->getId()) ? 'v2' : 'v1',
            'rolloutPercent' => $this->rollout->rolloutPercent(),
            'v1Deprecated' => true,
        ]);
    }
}
