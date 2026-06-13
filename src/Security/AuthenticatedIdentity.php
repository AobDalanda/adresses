<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\UserAccount;
use Symfony\Component\Security\Core\User\UserInterface;

final readonly class AuthenticatedIdentity implements UserInterface
{
    /**
     * @param list<string> $roles
     * @param array<string, mixed> $claims
     */
    public function __construct(
        public UserAccount $user,
        public string $tokenType,
        private array $roles,
        public array $claims
    ) {
    }

    public function getUserId(): int
    {
        return (int) $this->user->getId();
    }

    public function getUserIdentifier(): string
    {
        return sprintf('user:%d', $this->getUserId());
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        return $this->roles;
    }

    public function eraseCredentials(): void
    {
    }
}
