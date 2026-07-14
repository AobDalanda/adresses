<?php

declare(strict_types=1);

namespace App\Dto\Tracking;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class DriverLocationInput
{
    public function __construct(
        #[Assert\Positive]
        public int $driverId,
        #[Assert\Range(min: -90, max: 90)]
        public float $latitude,
        #[Assert\Range(min: -180, max: 180)]
        public float $longitude,
        #[Assert\PositiveOrZero]
        public float $accuracy,
        #[Assert\PositiveOrZero]
        public ?float $speed,
        #[Assert\Range(min: 0, max: 360)]
        public ?float $heading,
        #[Assert\Range(min: 0, max: 100)]
        public ?int $batteryLevel,
        #[Assert\NotBlank]
        #[Assert\Length(max: 30)]
        public string $source = 'gps',
        public ?\DateTimeImmutable $recordedAt = null,
        public bool $isMocked = false
    ) {
    }
}
