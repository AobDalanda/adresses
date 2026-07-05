<?php

declare(strict_types=1);

namespace App\Api\Controller;

use App\Security\TrackingIdentityResolver;
use App\Service\MissionOverviewService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final readonly class MissionDetailAction
{
    public function __construct(
        private TrackingIdentityResolver $identities,
        private MissionOverviewService $missions,
    ) {
    }

    public function __invoke(string $publicId, Request $request): JsonResponse
    {
        $identity = $this->identities->resolve($request);
        if ($identity === null) {
            return new JsonResponse(['message' => 'Unauthorized'], 401);
        }
        if (!$identity->isDriver() || $identity->userId === null) {
            return new JsonResponse(['message' => 'Forbidden'], 403);
        }

        try {
            return new JsonResponse($this->missions->detailForDriver($identity->userId, $publicId));
        } catch (\DomainException) {
            return new JsonResponse(['message' => 'Mission not found'], 404);
        } catch (\Throwable) {
            return new JsonResponse(['message' => 'Unable to load mission'], 500);
        }
    }
}
