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

        $proof = [
            'receptionCode' => $payload['receptionCode'] ?? null,
            'recipientName' => $payload['recipientName'] ?? null,
            'recipientSignatureAssetId' => $payload['recipientSignatureAssetId'] ?? null,
            'deliveryPhotoAssetId' => $payload['deliveryPhotoAssetId'] ?? null,
        ];

        foreach (['receptionCode', 'recipientName'] as $field) {
            if ($proof[$field] !== null && !is_string($proof[$field])) {
                return new JsonResponse(['message' => sprintf('%s est invalide', $field)], 400);
            }
        }
        foreach (['recipientSignatureAssetId', 'deliveryPhotoAssetId'] as $field) {
            if ($proof[$field] !== null && filter_var($proof[$field], FILTER_VALIDATE_INT) === false) {
                return new JsonResponse(['message' => sprintf('%s est invalide', $field)], 400);
            }
        }

        try {
            $result = $this->transitions->transition(
                $publicId,
                $identity->userId,
                strtoupper(trim($status)),
                $comment !== null ? trim($comment) : null,
                [
                    'receptionCode' => is_string($proof['receptionCode']) ? trim($proof['receptionCode']) : null,
                    'recipientName' => is_string($proof['recipientName']) ? trim($proof['recipientName']) : null,
                    'recipientSignatureAssetId' => $proof['recipientSignatureAssetId'] !== null ? (int) $proof['recipientSignatureAssetId'] : null,
                    'deliveryPhotoAssetId' => $proof['deliveryPhotoAssetId'] !== null ? (int) $proof['deliveryPhotoAssetId'] : null,
                ],
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
