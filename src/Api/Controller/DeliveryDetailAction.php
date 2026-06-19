<?php

namespace App\Api\Controller;

use App\Service\DeliveryOverviewService;
use App\Service\JwtAuthService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class DeliveryDetailAction
{
    public function __construct(
        private JwtAuthService $jwt,
        private DeliveryOverviewService $deliveries,
    ) {
    }

    public function __invoke(Request $request, string $publicId): JsonResponse
    {
        $auth = $this->jwt->decodeFromRequest($request);
        if (!$auth || ($auth['typ'] ?? null) !== 'mobile' || !isset($auth['uid'])) {
            return new JsonResponse(['message' => 'Unauthorized'], 401);
        }

        try {
            $payload = $this->deliveries->detailForUser((int) $auth['uid'], $publicId);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['message' => $e->getMessage()], 404);
        } catch (\Throwable) {
            return new JsonResponse(['message' => 'Erreur lors du chargement de la commande'], 500);
        }

        return new JsonResponse($payload);
    }
}
