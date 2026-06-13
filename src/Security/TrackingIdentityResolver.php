<?php

declare(strict_types=1);

namespace App\Security;

use App\Service\ProviderProfileService;
use Symfony\Component\HttpFoundation\Request;

final readonly class TrackingIdentityResolver
{
    public function __construct(
        private RequestIdentityResolver $identities,
        private ProviderProfileService $providerProfiles
    ) {
    }

    public function resolve(Request $request): ?TrackingIdentity
    {
        $identity = $this->identities->resolveMobile($request);
        if ($identity === null) {
            return null;
        }

        $user = $identity->user;
        $userId = $identity->getUserId();
        $roles = $identity->getRoles();

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
