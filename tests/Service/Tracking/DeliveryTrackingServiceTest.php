<?php

declare(strict_types=1);

namespace App\Tests\Service\Tracking;

use App\Entity\DriverLocation;
use App\Service\Tracking\DeliveryTrackingService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class DeliveryTrackingServiceTest extends TestCase
{
    public function testCustomerReceivesOnlyAssignedActiveDeliveryState(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchAssociative')->willReturnOnConsecutiveCalls(
            [
                'public_id' => 'delivery-42',
                'assigned_driver_id' => '15',
                'status' => 'IN_TRANSIT',
            ],
            [
                'latitude' => '9.6412',
                'longitude' => '-13.5784',
                'accuracy' => '5.3',
                'speed' => '18.5',
                'heading' => '220',
                'created_at' => '2026-06-30 21:15:00+00',
            ],
        );

        $state = (new DeliveryTrackingService($db))->stateForCustomer(9, 'delivery-42');

        self::assertSame('delivery/delivery-42/location', $state['topic']);
        self::assertSame(15, $state['driverId']);
        self::assertSame(9.6412, $state['location']['latitude']);
    }

    public function testInactiveDeliveryDoesNotExposeDriverLocation(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchAssociative')->willReturn([
            'public_id' => 'delivery-42',
            'assigned_driver_id' => '15',
            'status' => 'DELIVERED',
        ]);

        $this->expectException(\DomainException::class);
        (new DeliveryTrackingService($db))->stateForCustomer(9, 'delivery-42');
    }

    public function testLocationCreatesOneOutboxEventPerActiveDelivery(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchAllAssociative')->willReturn([
            ['public_id' => 'delivery-42'],
            ['public_id' => 'delivery-43'],
        ]);
        $db->expects(self::exactly(2))->method('executeStatement')->willReturn(1);

        $count = (new DeliveryTrackingService($db))->recordLocationEvents(
            new DriverLocation(15, 9.6412, -13.5784, 5.3, 18.5, 220.0, 74, 'gps'),
        );

        self::assertSame(2, $count);
    }
}
