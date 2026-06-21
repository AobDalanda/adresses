<?php

declare(strict_types=1);

namespace App\Api\Controller;

use App\Service\JwtAuthService;
use App\Service\ProviderApprovalStatusService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class ProviderApprovalStatusGetAction
{
    public function __construct(
        private readonly JwtAuthService $jwt,
        private readonly ProviderApprovalStatusService $approvalStatuses,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $claims = $this->jwt->decodeFromRequest($request);
        if (!is_array($claims) || ($claims['typ'] ?? null) !== 'mobile' || !isset($claims['uid'])) {
            return new JsonResponse(['message' => 'Unauthorized'], 401);
        }

        try {
            $status = $this->approvalStatuses->getForUserId((int) $claims['uid']);
        } catch (\RuntimeException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 404);
        }

        return new JsonResponse(['providerApproval' => $status]);
    }
}
