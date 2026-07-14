<?php

declare(strict_types=1);

namespace App\Service\Tracking;

use App\Dto\Tracking\DriverLocationHistoryItem;
use App\Dto\Tracking\DriverLocationInput;
use App\Dto\Tracking\DriverLocationOutput;
use App\Dto\Tracking\LocationHistoryQuery;
use App\Entity\DriverLocation;
use App\Repository\DriverLocationRepositoryInterface;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

final readonly class DriverTrackingService
{
    private const MAX_RECORDED_AGE_SECONDS = 300;
    private const MAX_FUTURE_DRIFT_SECONDS = 30;
    private const MAX_ACCEPTED_ACCURACY_METERS = 100.0;
    private const DEDUPLICATION_WINDOW_SECONDS = 5;
    private const MAX_JUMP_SPEED_KMH = 160.0;

    public function __construct(
        private DriverLocationRepositoryInterface $locations,
        private LocationPublisherInterface $publisher,
        private DeliveryTrackingService $deliveryTracking,
        private Connection $db,
        private LoggerInterface $trackingLogger
    ) {
    }

    public function saveLocation(DriverLocationInput $input): DriverLocationOutput
    {
        $this->assertRecordedAtIsFresh($input);

        $lastLocation = $this->locations->findLastForDriver($input->driverId);
        if ($this->isDuplicate($input, $lastLocation)) {
            $this->trackingLogger->info('GPS location deduplicated', ['driverId' => $input->driverId]);

            return DriverLocationOutput::fromEntity($lastLocation);
        }

        $isSuspect = $this->isSuspectJump($input, $lastLocation);
        $this->assertAccuracyIsUsable($input, $lastLocation);

        $location = new DriverLocation(
            $input->driverId,
            $input->latitude,
            $input->longitude,
            $input->accuracy,
            $input->speed,
            $input->heading,
            $input->batteryLevel,
            $input->source,
            $input->recordedAt,
            $input->isMocked,
            $isSuspect
        );

        try {
            $deliveryEvents = $this->db->transactional(function () use ($location): int {
                $this->locations->save($location);

                return $this->deliveryTracking->recordLocationEvents($location);
            });
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
            'recordedAt' => $location->getRecordedAt()->format(\DateTimeInterface::ATOM),
            'isMocked' => $location->isMocked(),
            'isSuspect' => $location->isSuspect(),
            'deliveryEvents' => $deliveryEvents,
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

    private function assertRecordedAtIsFresh(DriverLocationInput $input): void
    {
        if ($input->recordedAt === null) {
            return;
        }

        $delta = time() - $input->recordedAt->getTimestamp();
        if ($delta > self::MAX_RECORDED_AGE_SECONDS) {
            throw new \DomainException('recordedAt est trop ancien');
        }
        if ($delta < -self::MAX_FUTURE_DRIFT_SECONDS) {
            throw new \DomainException('recordedAt est dans le futur');
        }
    }

    private function assertAccuracyIsUsable(DriverLocationInput $input, ?DriverLocation $lastLocation): void
    {
        if ($input->accuracy <= self::MAX_ACCEPTED_ACCURACY_METERS) {
            return;
        }
        if ($lastLocation === null) {
            return;
        }
        if (($lastLocation->getCreatedAt()->getTimestamp() + 120) < time()) {
            return;
        }

        throw new \DomainException('accuracy est trop faible');
    }

    private function isDuplicate(DriverLocationInput $input, ?DriverLocation $lastLocation): bool
    {
        if ($lastLocation === null) {
            return false;
        }

        $sameCoordinates = abs($lastLocation->getLatitude() - $input->latitude) < 0.000001
            && abs($lastLocation->getLongitude() - $input->longitude) < 0.000001;
        if (!$sameCoordinates) {
            return false;
        }

        $inputTimestamp = ($input->recordedAt ?? new \DateTimeImmutable())->getTimestamp();
        $lastTimestamp = $lastLocation->getRecordedAt()->getTimestamp();

        return abs($inputTimestamp - $lastTimestamp) < self::DEDUPLICATION_WINDOW_SECONDS;
    }

    private function isSuspectJump(DriverLocationInput $input, ?DriverLocation $lastLocation): bool
    {
        if ($lastLocation === null) {
            return false;
        }

        $inputRecordedAt = $input->recordedAt ?? new \DateTimeImmutable();
        $seconds = $inputRecordedAt->getTimestamp() - $lastLocation->getRecordedAt()->getTimestamp();
        if ($seconds <= 0) {
            return false;
        }

        $distanceMeters = $this->distanceMeters(
            $lastLocation->getLatitude(),
            $lastLocation->getLongitude(),
            $input->latitude,
            $input->longitude
        );
        $speedKmh = ($distanceMeters / $seconds) * 3.6;

        return $speedKmh > self::MAX_JUMP_SPEED_KMH;
    }

    private function distanceMeters(float $latitudeA, float $longitudeA, float $latitudeB, float $longitudeB): float
    {
        $earthRadius = 6371000.0;
        $lat1 = deg2rad($latitudeA);
        $lat2 = deg2rad($latitudeB);
        $deltaLat = deg2rad($latitudeB - $latitudeA);
        $deltaLon = deg2rad($longitudeB - $longitudeA);

        $a = sin($deltaLat / 2) ** 2
            + cos($lat1) * cos($lat2) * sin($deltaLon / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }
}
