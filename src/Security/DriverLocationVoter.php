<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

final class DriverLocationVoter extends Voter
{
    public const PUBLISH = 'DRIVER_LOCATION_PUBLISH';
    public const VIEW = 'DRIVER_LOCATION_VIEW';

    public function canAccess(string $attribute, TrackingIdentity $identity, int $driverId): bool
    {
        if (!in_array($attribute, [self::PUBLISH, self::VIEW], true)) {
            return false;
        }

        if ($identity->isAdmin()) {
            return $attribute === self::VIEW;
        }

        return $identity->isDriver() && $identity->userId === $driverId;
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof DriverLocationAccess
            && in_array($attribute, [self::PUBLISH, self::VIEW], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        \assert($subject instanceof DriverLocationAccess);

        return $this->canAccess($attribute, $subject->identity, $subject->driverId);
    }
}
