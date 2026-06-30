<?php

declare(strict_types=1);

namespace App\Api\Controller;

use App\Security\TrackingIdentityResolver;
use App\Service\Tracking\DeliveryAssignmentService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final readonly class DeliveryAcceptAction
{
    public function __construct(
        private TrackingIdentityResolver $identities,
        private DeliveryAssignmentService $assignments,
    ) {
    }

    public function __invoke(string $publicId, Request $request): JsonResponse
    {
        $identity = $this->identities->resolve($request);
        if ($identity === null) {
            return new JsonResponse(['message' => 'Unauthorized'], 401);
        }
        if (!$identity->isDriver()) {
            return new JsonResponse(['message' => 'Forbidden'], 403);
        }

        try {
            return new JsonResponse($this->assignments->accept($publicId, $identity->userId));
        } catch (\DomainException) {
            return new JsonResponse(['message' => 'DELIVERY_NOT_AVAILABLE'], 409);
        } catch (\Throwable) {
            return new JsonResponse(['message' => 'Unable to assign delivery'], 500);
        }
    }
}
