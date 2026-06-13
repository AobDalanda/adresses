<?php

namespace App\Api\Controller;

use App\Entity\UserAccount;
use App\Security\RequestIdentityResolver;
use Symfony\Component\HttpFoundation\Request;

final class AuthenticatedUserResolver
{
    public function __construct(
        private readonly RequestIdentityResolver $identities
    ) {
    }

    public function requireMobileUser(Request $request): ?UserAccount
    {
        return $this->identities->resolveMobile($request)?->user;
    }
}
