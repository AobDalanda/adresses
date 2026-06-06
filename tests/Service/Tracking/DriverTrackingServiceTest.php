<?php

declare(strict_types=1);

namespace App\Tests\Service\Tracking;

use App\Dto\Tracking\DriverLocationInput;
use App\Dto\Tracking\LocationHistoryQuery;
use App\Entity\DriverLocation;
use App\Repository\DriverLocationRepositoryInterface;
use App\Service\Tracking\DriverTrackingService;
use App\Service\Tracking\LocationPublisherInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class DriverTrackingServiceTest extends TestCase
{
    public function testSavePersistsThenPublishesLocation(): void
    {
        $repository = $this->createMock(DriverLocationRepositoryInterface::class);
        $publisher = $this->createMock(LocationPublisherInterface::class);
        $repository->expects(self::once())->method('save')->with(self::isInstanceOf(DriverLocation::class));
        $publisher->expects(self::once())->method('publish')->willReturn(true);

        $service = new DriverTrackingService($repository, $publisher, new NullLogger());
        $output = $service->saveLocation(new DriverLocationInput(
            15,
            9.6412,
            -13.5784,
            5.3,
            18.5,
            220.0,
            74,
            'gps'
        ));

        self::assertSame(15, $output->driverId);
        self::assertSame(-13.5784, $output->longitude);
    }

    public function testReturnsHistoryDtos(): void
    {
        $location = new DriverLocation(15, 9.6, -13.5, 4.0, 10.0, 90.0, 80, 'gps');
        $repository = $this->createMock(DriverLocationRepositoryInterface::class);
        $repository->method('findHistory')->willReturn([$location]);

        $service = new DriverTrackingService(
            $repository,
            $this->createMock(LocationPublisherInterface::class),
            new NullLogger()
        );
        $history = $service->getLocationHistory(15, new LocationHistoryQuery(null, null, 100));

        self::assertCount(1, $history);
        self::assertSame(15, $history[0]->driverId);
        self::assertSame('gps', $history[0]->source);
    }

    public function testDelegatesPostgisAnalytics(): void
    {
        $repository = $this->createMock(DriverLocationRepositoryInterface::class);
        $repository->method('calculateDistance')->willReturn(1250.4);
        $repository->method('calculateAverageSpeed')->willReturn(21.5);

        $service = new DriverTrackingService(
            $repository,
            $this->createMock(LocationPublisherInterface::class),
            new NullLogger()
        );

        self::assertSame(1250.4, $service->calculateDistance(15));
        self::assertSame(21.5, $service->calculateAverageSpeed(15));
    }
}
