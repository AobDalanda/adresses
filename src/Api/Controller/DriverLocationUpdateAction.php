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

final class DriverLocationUpdateAction extends AbstractDriverTrackingAction
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

    public function __invoke(Request $request): JsonResponse
    {
        $identity = $this->requireIdentity($request);
        if ($identity instanceof JsonResponse) {
            return $identity;
        }

        try {
            $input = $this->requestMapper->mapLocation($request);
        } catch (\InvalidArgumentException $exception) {
            $this->trackingLogger->notice('Invalid GPS payload', ['message' => $exception->getMessage()]);

            return new JsonResponse(['message' => $exception->getMessage()], 400);
        }

        $denied = $this->authorize(DriverLocationVoter::PUBLISH, $identity, $input->driverId);
        if ($denied !== null) {
            return $denied;
        }

        $violations = $this->validator->validate($input);
        if (count($violations) > 0) {
            $this->trackingLogger->notice('GPS validation failed', [
                'driverId' => $input->driverId,
                'violations' => count($violations),
            ]);

            return $this->validationError($violations);
        }

        try {
            $this->tracking->saveLocation($input);
        } catch (\DomainException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 422);
        } catch (\Throwable) {
            return new JsonResponse(['message' => 'Unable to save location'], 500);
        }

        return new JsonResponse(['success' => true], 201);
    }
}
