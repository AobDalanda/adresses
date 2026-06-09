<?php

declare(strict_types=1);

namespace App\Security;

use App\Repository\UserAccountRepository;
use App\Service\JwtAuthService;
use App\Service\ProviderProfileService;
use Symfony\Component\HttpFoundation\Request;

final readonly class TrackingIdentityResolver
{
    public function __construct(
        private JwtAuthService $jwt,
        private UserAccountRepository $users,
        private ProviderProfileService $providerProfiles
    ) {
    }

    public function resolve(Request $request): ?TrackingIdentity
    {
        $claims = $this->jwt->decodeFromRequest($request);
        if (!is_array($claims)) {
            return null;
        }

        $roles = array_values(array_filter(
            is_array($claims['roles'] ?? null) ? $claims['roles'] : [],
            'is_string'
        ));
        $userId = isset($claims['uid']) ? (int) $claims['uid'] : null;

        if (in_array('ROLE_ADMIN', $roles, true)) {
            return new TrackingIdentity($userId, 'admin', $roles);
        }

        if (($claims['typ'] ?? null) !== 'mobile' || $userId === null) {
            return null;
        }

        $user = $this->users->find($userId);
        if ($user === null) {
            return null;
        }

        $providerProfile = $user->getAccountType() === 'provider'
            ? $this->providerProfiles->findByUserId($userId)
            : null;

        return new TrackingIdentity(
            $userId,
            $user->getAccountType(),
            $roles,
            (bool) ($providerProfile['canDeliver'] ?? false),
            ($providerProfile['validationStatus'] ?? null) === 'approved'
        );
    }
}
