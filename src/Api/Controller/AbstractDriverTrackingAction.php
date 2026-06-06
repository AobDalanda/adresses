<?php

declare(strict_types=1);

namespace App\Api\Controller;

use App\Security\DriverLocationVoter;
use App\Security\TrackingIdentity;
use App\Security\TrackingIdentityResolver;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\ConstraintViolationListInterface;

abstract class AbstractDriverTrackingAction
{
    public function __construct(
        protected readonly TrackingIdentityResolver $identityResolver,
        protected readonly DriverLocationVoter $voter,
        protected readonly LoggerInterface $trackingLogger
    ) {
    }

    protected function requireIdentity(Request $request): TrackingIdentity|JsonResponse
    {
        $identity = $this->identityResolver->resolve($request);
        if ($identity === null) {
            return new JsonResponse(['message' => 'Unauthorized'], 401);
        }

        return $identity;
    }

    protected function authorize(
        string $attribute,
        TrackingIdentity $identity,
        int $driverId
    ): ?JsonResponse {
        if ($this->voter->canAccess($attribute, $identity, $driverId)) {
            return null;
        }

        $this->trackingLogger->warning('Driver tracking security violation', [
            'attribute' => $attribute,
            'authenticatedUserId' => $identity->userId,
            'targetDriverId' => $driverId,
            'roles' => $identity->roles,
        ]);

        return new JsonResponse(['message' => 'Forbidden'], 403);
    }

    protected function validationError(ConstraintViolationListInterface $violations): JsonResponse
    {
        $errors = [];
        foreach ($violations as $violation) {
            $errors[$violation->getPropertyPath()][] = $violation->getMessage();
        }

        return new JsonResponse(['message' => 'Validation failed', 'errors' => $errors], 422);
    }
}
