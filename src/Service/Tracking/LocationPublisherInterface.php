<?php

declare(strict_types=1);

namespace App\Service\Tracking;

use App\Entity\DriverLocation;

interface LocationPublisherInterface
{
    public function publish(DriverLocation $location): bool;
}
