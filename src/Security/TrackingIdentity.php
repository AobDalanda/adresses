<?php

declare(strict_types=1);

namespace App\Security;

final readonly class TrackingIdentity
{
    /**
     * @param list<string> $roles
     */
    public function __construct(
        public ?int $userId,
        public string $accountType,
        public array $roles,
        public bool $canDeliver = false,
        public bool $providerApproved = false
    ) {
    }

    public function isAdmin(): bool
    {
        return in_array('ROLE_ADMIN', $this->roles, true);
    }

    public function isDriver(): bool
    {
        return $this->canDeliver && $this->providerApproved;
    }
}
