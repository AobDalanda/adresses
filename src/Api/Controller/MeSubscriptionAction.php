<?php

namespace App\Api\Controller;

use App\Exception\NoActiveSubscriptionException;
use App\Service\Subscription\MeSubscriptionViewFactory;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class MeSubscriptionAction
{
    public function __construct(
        private readonly AuthenticatedUserResolver $users,
        private readonly MeSubscriptionViewFactory $viewFactory
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->users->requireMobileUser($request);
        if ($user === null) {
            return new JsonResponse(['message' => 'Unauthorized'], 401);
        }

        try {
            return new JsonResponse($this->viewFactory->create($user));
        } catch (NoActiveSubscriptionException) {
            return new JsonResponse([
                'success' => false,
                'error' => [
                    'code' => 'SUBSCRIPTION_REQUIRED',
                    'message' => 'Aucun abonnement actif.',
                ],
            ], 403);
        }
    }
}
