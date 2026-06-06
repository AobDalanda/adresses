<?php

declare(strict_types=1);

namespace App\Api\Controller;

use App\Security\DriverLocationVoter;
use App\Security\TrackingIdentityResolver;
use App\Service\Tracking\DriverLocationRequestMapper;
use App\Service\Tracking\DriverTrackingService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class DriverLocationHistoryAction extends AbstractDriverTrackingAction
{
    public function __construct(
        TrackingIdentityResolver $identityResolver,
        DriverLocationVoter $voter,
        LoggerInterface $trackingLogger,
        private readonly DriverLocationRequestMapper $requestMapper,
        private readonly ValidatorInterface $validator,
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

        try {
            $query = $this->requestMapper->mapHistory($request);
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 400);
        }

        $violations = $this->validator->validate($query);
        if (count($violations) > 0) {
            return $this->validationError($violations);
        }

        $items = array_map(
            static fn ($item): array => $item->toArray(),
            $this->tracking->getLocationHistory($id, $query)
        );

        return new JsonResponse([
            'driverId' => $id,
            'items' => $items,
            'count' => count($items),
            'limit' => $query->limit,
        ]);
    }
}
