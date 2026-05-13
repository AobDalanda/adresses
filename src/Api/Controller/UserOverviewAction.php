<?php

namespace App\Api\Controller;

use App\Service\ConnectedUserOverviewService;
use App\Service\JwtAuthService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class UserOverviewAction
{
    public function __construct(
        private JwtAuthService $jwt,
        private ConnectedUserOverviewService $overview
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $auth = $this->jwt->decodeFromRequest($request);
        if (!$auth || ($auth['typ'] ?? null) !== 'mobile' || !isset($auth['uid'])) {
            return new JsonResponse(['message' => 'Unauthorized'], 401);
        }

        try {
            $data = $this->overview->getOverview((int) $auth['uid']);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['message' => $e->getMessage()], 404);
        } catch (\Throwable) {
            return new JsonResponse(['message' => 'Erreur lors du chargement du profil utilisateur'], 500);
        }

        return new JsonResponse($data);
    }
}
