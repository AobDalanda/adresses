<?php

declare(strict_types=1);

namespace App\Security;

use App\Repository\UserAccountRepository;
use App\Service\BackOfficeAccountService;
use App\Service\ProviderProfileService;

class AuthenticatedIdentityFactory
{
    public function __construct(
        private readonly UserAccountRepository $users,
        private readonly UserRoleProvider $roleProvider,
        private readonly ProviderProfileService $providerProfiles,
        private readonly BackOfficeAccountService $backOfficeAccounts
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

        $roles = ['ROLE_USER'];
        if ($this->providerProfiles->findByUserId((int) $user->getId()) !== null) {
            $roles[] = 'ROLE_PROVIDER';
        }

        return new AuthenticatedIdentity($user, 'mobile', array_values(array_unique($roles)), $claims);
    }

    /**
     * @param array<string, mixed> $claims
     */
    public function fromBackOfficeClaims(array $claims): ?AuthenticatedIdentity
    {
        if (
            ($claims['typ'] ?? null) !== 'back_office'
            || !$this->hasAudience($claims['aud'] ?? null, 'bo.aldahim.com')
            || !isset($claims['uid'])
        ) {
            return null;
        }

        $userId = (int) $claims['uid'];
        $user = $this->users->find($userId);
        if ($user === null || !$this->backOfficeAccounts->isEnabled($userId)) {
            return null;
        }

        $roles = array_merge(['ROLE_BACK_OFFICE'], $this->roleProvider->rolesForUser($userId));

        return new AuthenticatedIdentity($user, 'back_office', array_values(array_unique($roles)), $claims);
    }

    private function hasAudience(mixed $claim, string $expected): bool
    {
        if (is_string($claim)) {
            return $claim === $expected;
        }

        return is_array($claim) && in_array($expected, $claim, true);
    }
}
