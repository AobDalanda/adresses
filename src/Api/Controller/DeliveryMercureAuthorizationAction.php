<?php

declare(strict_types=1);

namespace App\Api\Controller;

use App\Security\TrackingIdentityResolver;
use App\Service\DeliveryOrderNotificationPublisherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Mercure\HubInterface;

final readonly class DeliveryMercureAuthorizationAction
{
    public function __construct(
        private TrackingIdentityResolver $identityResolver,
        private Authorization $mercureAuthorization,
        private HubInterface $hub,
        private LoggerInterface $logger
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $identity = $this->identityResolver->resolve($request);
        if ($identity === null) {
            return new JsonResponse(['message' => 'Unauthorized'], 401);
        }

        if (!$identity->isDriver()) {
            return new JsonResponse(['message' => 'Forbidden'], 403);
        }

        $topic = DeliveryOrderNotificationPublisherInterface::NEW_DELIVERY_ORDER_TOPIC;

        try {
            $this->mercureAuthorization->setCookie(
                $request,
                subscribe: [$topic],
                additionalClaims: [
                    'sub' => (string) $identity->userId,
                    'audience' => 'drivers',
                ]
            );
        } catch (\Throwable $exception) {
            $this->logger->error('Mercure delivery notification authorization failed', [
                'authenticatedUserId' => $identity->userId,
                'topic' => $topic,
                'exception' => $exception,
            ]);

            return new JsonResponse(['message' => 'Unable to authorize Mercure subscription'], 500);
        }

        return new JsonResponse([
            'hubUrl' => $this->hub->getPublicUrl(),
            'topic' => $topic,
        ]);
    }
}
