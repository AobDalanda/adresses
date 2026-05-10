<?php

namespace App\Api\Controller;

use App\Service\JwtAuthService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class AppAddressConfigAction
{
    public function __construct(
        private JwtAuthService $jwt,
        private int $targetAccuracyMeters,
        private int $maxSearchDurationSeconds
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $auth = $this->jwt->decodeFromRequest($request);
        if (!$auth || ($auth['typ'] ?? null) !== 'mobile' || !isset($auth['uid'])) {
            return new JsonResponse(['message' => 'Unauthorized'], 401);
        }

        return new JsonResponse([
            'targetAccuracyMeters' => $this->targetAccuracyMeters,
            'maxSearchDurationSeconds' => $this->maxSearchDurationSeconds,
        ]);
    }
}
