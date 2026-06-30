<?php

declare(strict_types=1);

namespace App\Api\Controller;

use App\Security\RequestIdentityResolver;
use App\Service\Tracking\DeliveryTrackingService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final readonly class DeliveryTrackingStateAction
{
    public function __construct(
        private RequestIdentityResolver $identities,
        private DeliveryTrackingService $tracking,
    ) {
    }

    public function __invoke(string $publicId, Request $request): JsonResponse
    {
        $identity = $this->identities->resolveMobile($request);
        if ($identity === null) {
            return new JsonResponse(['message' => 'Unauthorized'], 401);
        }

        try {
            return new JsonResponse($this->tracking->stateForCustomer($identity->getUserId(), $publicId));
        } catch (\OutOfBoundsException) {
            return new JsonResponse(['message' => 'DELIVERY_NOT_FOUND'], 404);
        } catch (\DomainException) {
            return new JsonResponse(['message' => 'TRACKING_NOT_ACTIVE'], 409);
        } catch (\Throwable) {
            return new JsonResponse(['message' => 'Unable to load tracking'], 500);
        }
    }
}
