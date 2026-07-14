<?php

declare(strict_types=1);

namespace App\Dto\Tracking;

use App\Entity\DriverLocation;

final readonly class DriverLocationHistoryItem
{
    public function __construct(
        public int $driverId,
        public float $latitude,
        public float $longitude,
        public float $accuracy,
        public ?float $speed,
        public ?float $heading,
        public ?int $batteryLevel,
        public string $source,
        public string $recordedAt,
        public string $receivedAt,
        public string $freshness,
        public bool $isMocked,
        public bool $isSuspect
    ) {
    }

    /**
     * @return array<string, float|int|string|null>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public static function fromEntity(DriverLocation $location): self
    {
        return new self(
            $location->getDriverId(),
            $location->getLatitude(),
            $location->getLongitude(),
            $location->getAccuracy(),
            $location->getSpeed(),
            $location->getHeading(),
            $location->getBatteryLevel(),
            $location->getSource(),
            $location->getRecordedAt()->format(\DateTimeInterface::ATOM),
            $location->getCreatedAt()->format(\DateTimeInterface::ATOM),
            DriverLocationOutput::fromEntity($location)->freshness,
            $location->isMocked(),
            $location->isSuspect()
        );
    }
}
