<?php

declare(strict_types=1);

namespace App\Api\Controller;

use App\Exception\DeliveryNotAvailableException;
use App\Exception\DeliveryNotFoundException;
use App\Exception\DeliveryOfferForbiddenException;
use App\Security\TrackingIdentityResolver;
use App\Service\DeliveryOfferService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final readonly class DeliveryOfferAction
{
    public function __construct(
        private TrackingIdentityResolver $identities,
        private DeliveryOfferService $offers,
    ) {
    }

    public function __invoke(string $publicId, Request $request): JsonResponse
    {
        $identity = $this->identities->resolve($request);
        if ($identity === null || $identity->userId === null) {
            return new JsonResponse(['message' => 'Unauthorized'], 401);
        }
        if (!$identity->isDriver()) {
            return new JsonResponse(['message' => 'Forbidden'], 403);
        }

        try {
            return new JsonResponse($this->offers->get($publicId, $identity->userId));
        } catch (DeliveryNotFoundException) {
            return new JsonResponse(['message' => 'DELIVERY_NOT_FOUND'], 404);
        } catch (DeliveryNotAvailableException) {
            return new JsonResponse(['message' => 'DELIVERY_ALREADY_ACCEPTED', 'available' => false], 409);
        } catch (DeliveryOfferForbiddenException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 403);
        }
    }
}
