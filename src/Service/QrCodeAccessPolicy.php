<?php

namespace App\Service;

final class QrCodeAccessPolicy
{
    /**
     * @param list<string> $allowedIps
     */
    public function __construct(private array $allowedIps = [])
    {
        $this->allowedIps = array_values(array_filter(array_map(
            static fn (mixed $ip): string => is_string($ip) ? trim($ip) : '',
            $allowedIps
        )));
    }

    public function isWhitelistEnabled(): bool
    {
        return $this->allowedIps !== [];
    }

    public function isIpAllowed(?string $ip): bool
    {
        if (!$this->isWhitelistEnabled()) {
            return true;
        }

        if ($ip === null || $ip === '') {
            return false;
        }

        return in_array($ip, $this->allowedIps, true);
    }

    public function canAccessAddress(array $address, ?int $authenticatedUserId): bool
    {
        $allowedUserId = isset($address['allowed_user_id']) && $address['allowed_user_id'] !== null
            ? (int) $address['allowed_user_id']
            : null;
        $createdBy = isset($address['created_by']) && $address['created_by'] !== null
            ? (int) $address['created_by']
            : null;

        if ($allowedUserId === null) {
            return true;
        }

        if ($authenticatedUserId === null) {
            return false;
        }

        if ($createdBy !== null && $createdBy === $authenticatedUserId) {
            return true;
        }

        return $allowedUserId === $authenticatedUserId;
    }

    public function resolveAuthenticatedUserId(?array $auth): ?int
    {
        if (!is_array($auth) || ($auth['typ'] ?? null) !== 'mobile' || !isset($auth['uid'])) {
            return null;
        }

        return (int) $auth['uid'];
    }
}
