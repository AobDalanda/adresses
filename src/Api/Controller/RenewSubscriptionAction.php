<?php

namespace App\Api\Controller;

use App\Service\Subscription\SubscriptionManager;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class RenewSubscriptionAction
{
    public function __construct(
        private readonly AuthenticatedUserResolver $users,
        private readonly SubscriptionManager $subscriptions
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->users->requireMobileUser($request);
        if ($user === null) {
            return new JsonResponse(['message' => 'Unauthorized'], 401);
        }

        $subscription = $this->subscriptions->renewSubscription($user);

        return new JsonResponse([
            'success' => true,
            'subscriptionId' => $subscription->getId(),
            'status' => $subscription->getStatus()->value,
        ]);
    }
}
