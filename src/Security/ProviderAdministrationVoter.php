<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

final class ProviderAdministrationVoter extends Voter
{
    public const LIST = 'PROVIDER_ADMIN_LIST';
    public const VIEW = 'PROVIDER_ADMIN_VIEW';
    public const DECIDE = 'PROVIDER_ADMIN_DECIDE';
    public const SUSPEND = 'PROVIDER_ADMIN_SUSPEND';

    private const SUPPORTED_ATTRIBUTES = [
        self::LIST,
        self::VIEW,
        self::DECIDE,
        self::SUSPEND,
    ];

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, self::SUPPORTED_ATTRIBUTES, true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof AuthenticatedIdentity) {
            return false;
        }

        $roles = $user->getRoles();
        if (in_array('ROLE_ADMIN', $roles, true)) {
            return true;
        }

        return match ($attribute) {
            self::LIST, self::VIEW => $this->hasAnyRole($roles, [
                'ROLE_PROVIDER_REVIEWER',
                'ROLE_PROVIDER_APPROVER',
                'ROLE_PROVIDER_SECURITY_ADMIN',
            ]),
            self::DECIDE => in_array('ROLE_PROVIDER_APPROVER', $roles, true),
            self::SUSPEND => in_array('ROLE_PROVIDER_SECURITY_ADMIN', $roles, true),
            default => false,
        };
    }

    /**
     * @param list<string> $roles
     * @param list<string> $expectedRoles
     */
    private function hasAnyRole(array $roles, array $expectedRoles): bool
    {
        return array_intersect($roles, $expectedRoles) !== [];
    }
}
