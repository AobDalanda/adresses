<?php

declare(strict_types=1);

namespace App\Service\Tracking;

use App\Dto\Tracking\DriverLocationHistoryItem;
use App\Dto\Tracking\DriverLocationInput;
use App\Dto\Tracking\DriverLocationOutput;
use App\Dto\Tracking\LocationHistoryQuery;
use App\Entity\DriverLocation;
use App\Repository\DriverLocationRepositoryInterface;
use Psr\Log\LoggerInterface;

final readonly class DriverTrackingService
{
    public function __construct(
        private DriverLocationRepositoryInterface $locations,
        private LocationPublisherInterface $publisher,
        private LoggerInterface $trackingLogger
    ) {
    }

    public function saveLocation(DriverLocationInput $input): DriverLocationOutput
    {
        $location = new DriverLocation(
            $input->driverId,
            $input->latitude,
            $input->longitude,
            $input->accuracy,
            $input->speed,
            $input->heading,
            $input->batteryLevel,
            $input->source
        );

        try {
            $this->locations->save($location);
        } catch (\Throwable $exception) {
            $this->trackingLogger->error('GPS location persistence failed', [
                'driverId' => $input->driverId,
                'exception' => $exception,
            ]);
            throw $exception;
        }

        $this->trackingLogger->info('GPS location updated', [
            'driverId' => $input->driverId,
            'accuracy' => $input->accuracy,
            'source' => $input->source,
        ]);
        $this->publisher->publish($location);

        return DriverLocationOutput::fromEntity($location);
    }

    public function getLastLocation(int $driverId): ?DriverLocationOutput
    {
        $location = $this->locations->findLastForDriver($driverId);

        return $location === null ? null : DriverLocationOutput::fromEntity($location);
    }

    /**
     * @return list<DriverLocationHistoryItem>
     */
    public function getLocationHistory(int $driverId, LocationHistoryQuery $query): array
    {
        return array_map(
            DriverLocationHistoryItem::fromEntity(...),
            $this->locations->findHistory($driverId, $query->from, $query->to, $query->limit)
        );
    }

    public function calculateDistance(
        int $driverId,
        ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $to = null
    ): float {
        return $this->locations->calculateDistance($driverId, $from, $to);
    }

    public function calculateAverageSpeed(
        int $driverId,
        ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $to = null
    ): ?float {
        return $this->locations->calculateAverageSpeed($driverId, $from, $to);
    }
}
