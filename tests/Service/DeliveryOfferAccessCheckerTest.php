<?php
declare(strict_types=1);
namespace App\Tests\Service;

use App\Exception\DeliveryOfferForbiddenException;
use App\Service\DeliveryOfferAccessChecker;
use App\Service\DriverAccessChecker;
use App\Service\NearbyDriverMatcher;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class DeliveryOfferAccessCheckerTest extends TestCase
{
    public function testAvailableDriverInPickupZoneCanConsultOffer(): void
    {
        $checker = $this->checker(null);
        $checker->assertEligible(15, $this->offer());
        self::addToAssertionCount(1);
    }

    #[DataProvider('forbiddenCases')]
    public function testIneligibleDriverIsForbidden(string $matcherReason, string $reason): void
    {
        $this->expectException(DeliveryOfferForbiddenException::class);
        $this->expectExceptionMessage($reason);
        $this->checker($matcherReason)->assertEligible(15, $this->offer());
    }

    /** @return iterable<string, array{string, string}> */
    public static function forbiddenCases(): iterable
    {
        yield 'indisponible' => ['OFFLINE', 'DRIVER_UNAVAILABLE'];
        yield 'hors zone' => ['OUTSIDE_RADIUS', 'DRIVER_OUTSIDE_PICKUP_ZONE'];
        yield 'sans position' => ['LOCATION_MISSING', 'DRIVER_LOCATION_STALE'];
        yield 'position ancienne' => ['LOCATION_STALE', 'DRIVER_LOCATION_STALE'];
    }

    private function checker(?string $rejectionReason): DeliveryOfferAccessChecker
    {
        $accessDb = $this->createMock(Connection::class);
        $accessDb->method('fetchOne')->willReturn(1);
        $matcherDb = $this->createMock(Connection::class);
        $matcherDb->method('fetchAllAssociative')->willReturn([[
            'driver_id' => 15,
            'online' => $rejectionReason === 'OFFLINE' ? false : true,
            'effective_online' => !in_array($rejectionReason, ['OFFLINE', 'LOCATION_MISSING', 'LOCATION_STALE'], true),
            'location_age_seconds' => $rejectionReason === 'LOCATION_MISSING' ? null : ($rejectionReason === 'LOCATION_STALE' ? 240 : 10),
            'accuracy_meters' => 5,
            'distance_meters' => $rejectionReason === 'OUTSIDE_RADIUS' ? 1001 : 120,
            'service_compatible' => true,
            'vehicle_compatible' => true,
            'country_compatible' => true,
            'account_validated' => true,
        ]]);
        return new DeliveryOfferAccessChecker(
            new DriverAccessChecker($accessDb),
            new NearbyDriverMatcher($matcherDb, new NullLogger(), 180, 100),
            1000,
        );
    }

    /** @return array<string, mixed> */
    private function offer(): array
    {
        return [
            'status' => 'QUOTED', 'assigned_driver_id' => null,
            'pickup_latitude' => 9.6, 'pickup_longitude' => -13.6,
            'pickup_country_code' => 'GN', 'service_type_code' => 'DELIVERY',
            'vehicle_type_code' => 'MOTORCYCLE',
        ];
    }
}
