<?php

declare(strict_types=1);

namespace App\Service\Tracking;

use App\Dto\Tracking\DriverLocationOutput;
use App\Entity\DriverLocation;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final readonly class LocationPublisher implements LocationPublisherInterface
{
    public function __construct(
        private HubInterface $hub,
        private LoggerInterface $trackingLogger
    ) {
    }

    public function publish(DriverLocation $location): bool
    {
        $topic = sprintf('driver/%d/location', $location->getDriverId());
        $payload = [
            'driverId' => $location->getDriverId(),
            'latitude' => $location->getLatitude(),
            'longitude' => $location->getLongitude(),
            'accuracy' => $location->getAccuracy(),
            'speed' => $location->getSpeed(),
            'heading' => $location->getHeading(),
            'source' => $location->getSource(),
            'recordedAt' => $location->getRecordedAt()->format(\DateTimeInterface::ATOM),
            'receivedAt' => $location->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'freshness' => DriverLocationOutput::fromEntity($location)->freshness,
            'isMocked' => $location->isMocked(),
            'isSuspect' => $location->isSuspect(),
            'timestamp' => $location->getCreatedAt()->getTimestamp(),
        ];

        try {
            $this->hub->publish(new Update(
                $topic,
                json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
                private: true
            ));
            $this->trackingLogger->info('Mercure location published', [
                'driverId' => $location->getDriverId(),
                'topic' => $topic,
            ]);

            return true;
        } catch (\Throwable $exception) {
            $this->trackingLogger->error('Mercure location publication failed', [
                'driverId' => $location->getDriverId(),
                'topic' => $topic,
                'exception' => $exception,
            ]);

            return false;
        }
    }
}
