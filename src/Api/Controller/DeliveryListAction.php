<?php

namespace App\Api\Controller;

use App\Service\DeliveryOverviewService;
use App\Service\JwtAuthService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class DeliveryListAction
{
    public function __construct(
        private JwtAuthService $jwt,
        private DeliveryOverviewService $deliveries,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $auth = $this->jwt->decodeFromRequest($request);
        if (!$auth || ($auth['typ'] ?? null) !== 'mobile' || !isset($auth['uid'])) {
            return new JsonResponse(['message' => 'Unauthorized'], 401);
        }

        $status = $request->query->getString('status', 'all');
        $page = max(1, $request->query->getInt('page', 1));
        $perPage = max(1, $request->query->getInt('perPage', 20));

        try {
            $payload = $this->deliveries->listForUser((int) $auth['uid'], $status, $page, $perPage);
        } catch (\Throwable) {
            return new JsonResponse(['message' => 'Erreur lors du chargement des commandes'], 500);
        }

        return new JsonResponse($payload);
    }
}
