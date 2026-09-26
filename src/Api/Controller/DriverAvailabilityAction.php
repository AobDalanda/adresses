<?php

declare(strict_types=1);

namespace App\Api\Controller;

use App\Security\TrackingIdentityResolver;
use App\Service\Tracking\DriverAvailabilityService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final readonly class DriverAvailabilityAction
{
    public function __construct(
        private TrackingIdentityResolver $identityResolver,
        private DriverAvailabilityService $availability,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $identity = $this->identityResolver->resolve($request);
        if ($identity === null || $identity->userId === null) {
            return new JsonResponse(['message' => 'Unauthorized'], 401);
        }
        if ($request->isMethod('GET')) {
            return new JsonResponse($this->availability->get($identity->userId));
        }

        try {
            $payload = $request->toArray();
        } catch (\Throwable) {
            return new JsonResponse([
                'error' => 'INVALID_PAYLOAD',
                'message' => 'Le payload JSON est invalide.',
            ], 400);
        }

        if (!array_key_exists('online', $payload) || !is_bool($payload['online'])) {
            return new JsonResponse([
                'error' => 'INVALID_ONLINE_VALUE',
                'message' => 'Le champ online doit être un booléen.',
            ], 400);
        }
        if ($payload['online'] && !$identity->isDriver()) {
            return new JsonResponse([
                'error' => 'PROVIDER_NOT_AUTHORIZED',
                'message' => 'Ce compte ne peut pas activer la disponibilité prestataire.',
            ], 422);
        }

        try {
            // availabilityVersion is intentionally ignored during the mobile compatibility period.
            $state = $this->availability->set($identity->userId, $payload['online']);
        } catch (\DomainException $exception) {
            return new JsonResponse([
                'error' => 'PROVIDER_NOT_AUTHORIZED',
                'message' => $exception->getMessage(),
            ], 422);
        } catch (\Throwable) {
            return new JsonResponse([
                'error' => 'AVAILABILITY_UPDATE_FAILED',
                'message' => 'Impossible de modifier la disponibilité.',
            ], 500);
        }

        return new JsonResponse($state);
    }
}
