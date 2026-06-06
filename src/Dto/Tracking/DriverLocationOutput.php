<?php

declare(strict_types=1);

namespace App\Dto\Tracking;

use App\Entity\DriverLocation;

final readonly class DriverLocationOutput
{
    public function __construct(
        public int $driverId,
        public float $latitude,
        public float $longitude,
        public ?float $speed,
        public ?float $heading,
        public string $updatedAt
    ) {
    }

    /**
     * @return array{driverId: int, latitude: float, longitude: float, speed: ?float, heading: ?float, updatedAt: string}
     */
    public function toArray(): array
    {
        return [
            'driverId' => $this->driverId,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'speed' => $this->speed,
            'heading' => $this->heading,
            'updatedAt' => $this->updatedAt,
        ];
    }

    public static function fromEntity(DriverLocation $location): self
    {
        return new self(
            $location->getDriverId(),
            $location->getLatitude(),
            $location->getLongitude(),
            $location->getSpeed(),
            $location->getHeading(),
            $location->getCreatedAt()->format(\DateTimeInterface::ATOM)
        );
    }
}
