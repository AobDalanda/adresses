<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DriverLocationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DriverLocationRepository::class)]
#[ORM\Table(name: 'driver_location')]
#[ORM\Index(name: 'idx_driver_location_driver', columns: ['driver_id'])]
#[ORM\Index(name: 'idx_driver_location_created_at', columns: ['created_at'])]
class DriverLocation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(type: 'bigint')]
    private int $driverId;

    #[ORM\Column]
    private float $latitude;

    #[ORM\Column]
    private float $longitude;

    #[ORM\Column]
    private float $accuracy;

    #[ORM\Column(nullable: true)]
    private ?float $speed;

    #[ORM\Column(nullable: true)]
    private ?float $heading;

    #[ORM\Column(nullable: true)]
    private ?int $batteryLevel;

    #[ORM\Column(length: 30)]
    private string $source;

    #[ORM\Column(type: 'geography', options: ['geometry_type' => 'POINT', 'srid' => 4326])]
    private string $position;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        int $driverId,
        float $latitude,
        float $longitude,
        float $accuracy,
        ?float $speed,
        ?float $heading,
        ?int $batteryLevel,
        string $source,
        ?\DateTimeImmutable $createdAt = null
    ) {
        $this->driverId = $driverId;
        $this->latitude = $latitude;
        $this->longitude = $longitude;
        $this->accuracy = $accuracy;
        $this->speed = $speed;
        $this->heading = $heading;
        $this->batteryLevel = $batteryLevel;
        $this->source = $source;
        $this->position = sprintf('SRID=4326;POINT(%F %F)', $longitude, $latitude);
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDriverId(): int
    {
        return $this->driverId;
    }

    public function getLatitude(): float
    {
        return $this->latitude;
    }

    public function getLongitude(): float
    {
        return $this->longitude;
    }

    public function getAccuracy(): float
    {
        return $this->accuracy;
    }

    public function getSpeed(): ?float
    {
        return $this->speed;
    }

    public function getHeading(): ?float
    {
        return $this->heading;
    }

    public function getBatteryLevel(): ?int
    {
        return $this->batteryLevel;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getPosition(): string
    {
        return $this->position;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
