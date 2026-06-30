<?php

declare(strict_types=1);

namespace App\Api\Controller;

use App\Security\RequestIdentityResolver;
use App\Service\Tracking\DeliveryTrackingService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Mercure\HubInterface;

final readonly class DeliveryTrackingAuthorizationAction
{
    public function __construct(
        private RequestIdentityResolver $identities,
        private DeliveryTrackingService $tracking,
        private Authorization $authorization,
        private HubInterface $hub,
    ) {
    }

    public function __invoke(string $publicId, Request $request): JsonResponse
    {
        $identity = $this->identities->resolveMobile($request);
        if ($identity === null) {
            return new JsonResponse(['message' => 'Unauthorized'], 401);
        }

        try {
            $state = $this->tracking->stateForCustomer($identity->getUserId(), $publicId);
            $this->authorization->setCookie(
                $request,
                subscribe: [$state['topic']],
                additionalClaims: [
                    'sub' => (string) $identity->getUserId(),
                    'delivery' => $publicId,
                ],
            );
        } catch (\OutOfBoundsException) {
            return new JsonResponse(['message' => 'DELIVERY_NOT_FOUND'], 404);
        } catch (\DomainException) {
            return new JsonResponse(['message' => 'TRACKING_NOT_ACTIVE'], 409);
        } catch (\Throwable) {
            return new JsonResponse(['message' => 'Unable to authorize tracking'], 500);
        }

        return new JsonResponse([
            'hubUrl' => $this->hub->getPublicUrl(),
            'topic' => $state['topic'],
        ]);
    }
}
