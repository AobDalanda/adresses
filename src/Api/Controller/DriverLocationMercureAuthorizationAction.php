<?php

declare(strict_types=1);

namespace App\Api\Controller;

use App\Security\DriverLocationVoter;
use App\Security\TrackingIdentityResolver;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Mercure\HubInterface;

final class DriverLocationMercureAuthorizationAction extends AbstractDriverTrackingAction
{
    public function __construct(
        TrackingIdentityResolver $identityResolver,
        DriverLocationVoter $voter,
        LoggerInterface $trackingLogger,
        private readonly Authorization $mercureAuthorization,
        private readonly HubInterface $hub
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

        $topic = sprintf('driver/%d/location', $id);

        try {
            $this->mercureAuthorization->setCookie(
                $request,
                subscribe: [$topic],
                additionalClaims: [
                    'sub' => (string) ($identity->userId ?? 'admin'),
                ]
            );
        } catch (\Throwable $exception) {
            $this->trackingLogger->error('Mercure subscription authorization failed', [
                'authenticatedUserId' => $identity->userId,
                'targetDriverId' => $id,
                'topic' => $topic,
                'exception' => $exception,
            ]);

            return new JsonResponse(['message' => 'Unable to authorize Mercure subscription'], 500);
        }

        $this->trackingLogger->info('Mercure subscription authorized', [
            'authenticatedUserId' => $identity->userId,
            'targetDriverId' => $id,
            'topic' => $topic,
        ]);

        return new JsonResponse([
            'hubUrl' => $this->hub->getPublicUrl(),
            'topic' => $topic,
        ]);
    }
}
