<?php

namespace App\Api\Controller;

use App\Entity\UserAccount;
use App\Service\JwtAuthService;
use App\Service\Subscription\SubscriptionManager;
use Symfony\Component\HttpFoundation\Request;

final class AuthenticatedUserResolver
{
    public function __construct(
        private readonly JwtAuthService $jwt,
        private readonly SubscriptionManager $subscriptions
    ) {
    }

    public function requireMobileUser(Request $request): ?UserAccount
    {
        $auth = $this->jwt->decodeFromRequest($request);
        if (!$auth || ($auth['typ'] ?? null) !== 'mobile' || !isset($auth['uid'])) {
            return null;
        }

        return $this->subscriptions->getUser((int) $auth['uid']);
    }
}
