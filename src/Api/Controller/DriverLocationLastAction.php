<?php

declare(strict_types=1);

namespace App\Api\Controller;

use App\Security\DriverLocationVoter;
use App\Security\TrackingIdentityResolver;
use App\Service\Tracking\DriverTrackingService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class DriverLocationLastAction extends AbstractDriverTrackingAction
{
    public function __construct(
        TrackingIdentityResolver $identityResolver,
        DriverLocationVoter $voter,
        LoggerInterface $trackingLogger,
        private readonly DriverTrackingService $tracking
    ) {
        parent::__construct($identityResolver, $voter, $trackingLogger);
    }

    public function __invoke(int $id, Request $request): JsonResponse
    {
        $identity = $this->requireIdentity($request);
        if ($identity instanceof JsonResponse) {
            return $identity;
        }

        $denied = $this->authorize(DriverLocationVoter::VIEW, $identity, $id);
        if ($denied !== null) {
            return $denied;
        }

        $location = $this->tracking->getLastLocation($id);
        if ($location === null) {
            return new JsonResponse(['message' => 'Location not found'], 404);
        }

        return new JsonResponse($location->toArray());
    }
}
