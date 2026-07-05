<?php

declare(strict_types=1);

namespace App\Api\Controller;

use App\Security\TrackingIdentityResolver;
use App\Service\Tracking\DeliveryStatusTransitionService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final readonly class DeliveryUpdateStatusAction
{
    public function __construct(
        private TrackingIdentityResolver $identities,
        private DeliveryStatusTransitionService $transitions,
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

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['message' => 'Invalid JSON body'], 400);
        }

        $status = $payload['status'] ?? null;
        if (!is_string($status) || trim($status) === '') {
            return new JsonResponse(['message' => 'status est requis'], 400);
        }

        $comment = $payload['comment'] ?? null;
        if ($comment !== null && !is_string($comment)) {
            return new JsonResponse(['message' => 'comment est invalide'], 400);
        }

        try {
            $result = $this->transitions->transition(
                $publicId,
                $identity->userId,
                strtoupper(trim($status)),
                $comment !== null ? trim($comment) : null,
            );
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 400);
        } catch (\OutOfBoundsException) {
            return new JsonResponse(['message' => 'MISSION_NOT_FOUND'], 404);
        } catch (\DomainException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 409);
        } catch (\Throwable) {
            return new JsonResponse(['message' => 'Unable to update delivery status'], 500);
        }

        return new JsonResponse($result, 200);
    }
}
