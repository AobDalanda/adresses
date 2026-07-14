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
        public float $accuracy,
        public ?float $speed,
        public ?float $heading,
        public string $source,
        public string $recordedAt,
        public string $receivedAt,
        public string $freshness,
        public bool $isMocked,
        public bool $isSuspect
    ) {
    }

    /**
     * @return array{
     *     driverId: int,
     *     location: array{
     *         latitude: float,
     *         longitude: float,
     *         accuracy: float,
     *         speed: ?float,
     *         heading: ?float,
     *         source: string,
     *         recordedAt: string,
     *         receivedAt: string,
     *         freshness: string,
     *         isMocked: bool,
     *         isSuspect: bool
     *     }
     * }
     */
    public function toArray(): array
    {
        return [
            'driverId' => $this->driverId,
            'location' => [
                'latitude' => $this->latitude,
                'longitude' => $this->longitude,
                'accuracy' => $this->accuracy,
                'speed' => $this->speed,
                'heading' => $this->heading,
                'source' => $this->source,
                'recordedAt' => $this->recordedAt,
                'receivedAt' => $this->receivedAt,
                'freshness' => $this->freshness,
                'isMocked' => $this->isMocked,
                'isSuspect' => $this->isSuspect,
            ],
        ];
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
            $location->getSource(),
            $location->getRecordedAt()->format(\DateTimeInterface::ATOM),
            $location->getCreatedAt()->format(\DateTimeInterface::ATOM),
            self::freshnessFor($location->getCreatedAt()),
            $location->isMocked(),
            $location->isSuspect()
        );
    }

    private static function freshnessFor(\DateTimeImmutable $receivedAt): string
    {
        $age = time() - $receivedAt->getTimestamp();

        return match (true) {
            $age < 30 => 'fresh',
            $age < 120 => 'stale',
            default => 'offline',
        };
    }
}
