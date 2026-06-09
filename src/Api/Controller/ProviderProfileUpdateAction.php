<?php

declare(strict_types=1);

namespace App\Api\Controller;

use App\Service\JwtAuthService;
use App\Service\ProviderProfileService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class ProviderProfileUpdateAction
{
    public function __construct(
        private readonly JwtAuthService $jwt,
        private readonly ProviderProfileService $providers
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $claims = $this->jwt->decodeFromRequest($request);
        if (!is_array($claims) || ($claims['typ'] ?? null) !== 'mobile' || !isset($claims['uid'])) {
            return new JsonResponse(['message' => 'Unauthorized'], 401);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['message' => 'Invalid JSON body'], 400);
        }

        $canDeliver = $payload['canDeliver'] ?? null;
        $canTransportPeople = $payload['canTransportPeople'] ?? null;
        if (!is_bool($canDeliver) || !is_bool($canTransportPeople)) {
            return new JsonResponse([
                'message' => 'canDeliver et canTransportPeople sont requis et doivent être booléens',
            ], 400);
        }

        try {
            $profile = $this->providers->submitActivities(
                (int) $claims['uid'],
                $canDeliver,
                $canTransportPeople
            );
        } catch (\InvalidArgumentException|\DomainException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 400);
        } catch (\RuntimeException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 404);
        } catch (\Throwable) {
            return new JsonResponse(['message' => 'Erreur lors de la mise à jour du profil prestataire'], 500);
        }

        return new JsonResponse(['providerProfile' => $profile]);
    }
}
