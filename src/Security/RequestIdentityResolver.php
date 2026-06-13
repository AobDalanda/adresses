<?php

declare(strict_types=1);

namespace App\Security;

use App\Service\JwtAuthService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;

final readonly class RequestIdentityResolver
{
    public function __construct(
        private Security $security,
        private JwtAuthService $jwt,
        private AuthenticatedIdentityFactory $identities
    ) {
    }

    public function resolveMobile(Request $request): ?AuthenticatedIdentity
    {
        $authenticatedUser = $this->security->getUser();
        if ($authenticatedUser instanceof AuthenticatedIdentity) {
            return $authenticatedUser->tokenType === 'mobile' ? $authenticatedUser : null;
        }

        $claims = $this->jwt->decodeFromRequest($request);

        return is_array($claims) ? $this->identities->fromMobileClaims($claims) : null;
    }
}
