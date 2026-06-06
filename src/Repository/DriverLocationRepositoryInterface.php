<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DriverLocation;

interface DriverLocationRepositoryInterface
{
    public function save(DriverLocation $location): void;

    public function findLastForDriver(int $driverId): ?DriverLocation;

    /**
     * @return list<DriverLocation>
     */
    public function findHistory(
        int $driverId,
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $to,
        int $limit
    ): array;

    public function calculateDistance(
        int $driverId,
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $to
    ): float;

    public function calculateAverageSpeed(
        int $driverId,
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $to
    ): ?float;
}
