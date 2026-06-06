<?php

declare(strict_types=1);

namespace App\Security;

final readonly class DriverLocationAccess
{
    public function __construct(
        public TrackingIdentity $identity,
        public int $driverId
    ) {
    }
}
