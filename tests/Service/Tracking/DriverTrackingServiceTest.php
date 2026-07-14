<?php

declare(strict_types=1);

namespace App\Tests\Service\Tracking;

use App\Dto\Tracking\DriverLocationInput;
use App\Dto\Tracking\LocationHistoryQuery;
use App\Entity\DriverLocation;
use App\Repository\DriverLocationRepositoryInterface;
use App\Service\Tracking\DriverTrackingService;
use App\Service\Tracking\DeliveryTrackingService;
use App\Service\Tracking\LocationPublisherInterface;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class DriverTrackingServiceTest extends TestCase
{
    public function testSavePersistsThenPublishesLocation(): void
    {
        $repository = $this->createMock(DriverLocationRepositoryInterface::class);
        $publisher = $this->createMock(LocationPublisherInterface::class);
        $repository->expects(self::once())->method('save')->with(self::isInstanceOf(DriverLocation::class));
        $repository->method('findLastForDriver')->willReturn(null);
        $publisher->expects(self::once())->method('publish')->willReturn(true);

        $db = $this->trackingConnection();
        $service = new DriverTrackingService(
            $repository,
            $publisher,
            new DeliveryTrackingService($db),
            $db,
            new NullLogger(),
        );
        $output = $service->saveLocation(new DriverLocationInput(
            15,
            9.6412,
            -13.5784,
            5.3,
            18.5,
            220.0,
            74,
            'gps',
            new \DateTimeImmutable('-10 seconds'),
            false
        ));

        self::assertSame(15, $output->driverId);
        self::assertSame(-13.5784, $output->longitude);
        self::assertSame('fresh', $output->freshness);
    }

    public function testReturnsHistoryDtos(): void
    {
        $location = new DriverLocation(15, 9.6, -13.5, 4.0, 10.0, 90.0, 80, 'gps');
        $repository = $this->createMock(DriverLocationRepositoryInterface::class);
        $repository->method('findHistory')->willReturn([$location]);

        $service = new DriverTrackingService(
            $repository,
            $this->createMock(LocationPublisherInterface::class),
            new DeliveryTrackingService($db = $this->trackingConnection()),
            $db,
            new NullLogger(),
        );
        $history = $service->getLocationHistory(15, new LocationHistoryQuery(null, null, 100));

        self::assertCount(1, $history);
        self::assertSame(15, $history[0]->driverId);
        self::assertSame('gps', $history[0]->source);
        self::assertSame('fresh', $history[0]->freshness);
    }

    public function testDelegatesPostgisAnalytics(): void
    {
        $repository = $this->createMock(DriverLocationRepositoryInterface::class);
        $repository->method('calculateDistance')->willReturn(1250.4);
        $repository->method('calculateAverageSpeed')->willReturn(21.5);

        $service = new DriverTrackingService(
            $repository,
            $this->createMock(LocationPublisherInterface::class),
            new DeliveryTrackingService($db = $this->trackingConnection()),
            $db,
            new NullLogger(),
        );

        self::assertSame(1250.4, $service->calculateDistance(15));
        self::assertSame(21.5, $service->calculateAverageSpeed(15));
    }

    public function testRejectsLocationThatIsTooOld(): void
    {
        $repository = $this->createMock(DriverLocationRepositoryInterface::class);
        $repository->method('findLastForDriver')->willReturn(null);

        $service = new DriverTrackingService(
            $repository,
            $this->createMock(LocationPublisherInterface::class),
            new DeliveryTrackingService($db = $this->trackingConnection()),
            $db,
            new NullLogger(),
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('recordedAt est trop ancien');
        $service->saveLocation(new DriverLocationInput(
            15,
            9.6412,
            -13.5784,
            5.3,
            null,
            null,
            null,
            'gps',
            new \DateTimeImmutable('-10 minutes'),
            false
        ));
    }

    private function trackingConnection(): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('transactional')->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $db->method('fetchAllAssociative')->willReturn([]);

        return $db;
    }
}
