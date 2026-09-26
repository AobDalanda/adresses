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
            return new JsonResponse(['message' => 'Invalid JSON payload'], 400);
        }

        if (!array_key_exists('online', $payload) || !is_bool($payload['online'])) {
            return new JsonResponse(['message' => 'online must be a boolean'], 400);
        }
        if (
            !array_key_exists('availabilityVersion', $payload)
            || !is_int($payload['availabilityVersion'])
            || $payload['availabilityVersion'] < 0
        ) {
            return new JsonResponse(['message' => 'availabilityVersion must be a non-negative integer'], 400);
        }
        if ($payload['online'] && !$identity->isDriver()) {
            return new JsonResponse(['message' => 'Forbidden'], 403);
        }

        try {
            $state = $this->availability->set(
                $identity->userId,
                $payload['online'],
                $payload['availabilityVersion'],
            );
        } catch (\DomainException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 422);
        }

        return new JsonResponse($state);
    }
}
