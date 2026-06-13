<?php

declare(strict_types=1);

namespace App\Security;

use App\Repository\UserAccountRepository;

class AuthenticatedIdentityFactory
{
    public function __construct(
        private readonly UserAccountRepository $users,
        private readonly UserRoleProvider $roleProvider
    ) {
    }

    /**
     * @param array<string, mixed> $claims
     */
    public function fromMobileClaims(array $claims): ?AuthenticatedIdentity
    {
        if (($claims['typ'] ?? null) !== 'mobile' || !isset($claims['uid'])) {
            return null;
        }

        $user = $this->users->find((int) $claims['uid']);
        if ($user === null) {
            return null;
        }

        $roles = array_merge(['ROLE_USER'], $this->roleProvider->rolesForUser((int) $user->getId()));
        if ($user->getAccountType() === 'provider') {
            $roles[] = 'ROLE_PROVIDER';
        } elseif ($user->getAccountType() === 'admin') {
            $roles[] = 'ROLE_ADMIN';
        }

        return new AuthenticatedIdentity($user, 'mobile', array_values(array_unique($roles)), $claims);
    }
}
