<?php

declare(strict_types=1);

namespace App\Tests\Service\Tracking;

use App\Service\Tracking\DriverAvailabilityService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class DriverAvailabilityServiceTest extends TestCase
{
    public function testUnknownDriverIsOffline(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchAssociative')->willReturn(false);

        self::assertSame(
            [
                'requestedOnline' => false,
                'online' => false,
                'effectiveOnline' => false,
                'availabilityVersion' => 0,
                'changedAt' => null,
                'lastLocationAt' => null,
            ],
            (new DriverAvailabilityService($db))->get(42),
        );
    }

    public function testEligibleDriverCanRequestOnlineWithAMonotonicVersion(): void
    {
        $statements = [];
        $db = $this->createMock(Connection::class);
        $db->method('fetchOne')->willReturn(true);
        $db->method('fetchAssociative')->willReturn([
            'requested_online' => true,
            'effective_online' => false,
            'availability_version' => 1,
            'changed_at' => '2026-09-26 10:00:00+00',
            'last_location_at' => null,
        ]);
        $db->method('executeStatement')->willReturnCallback(
            static function (string $sql, array $params = []) use (&$statements): int {
                $statements[] = [$sql, $params];

                return 1;
            },
        );
        $state = (new DriverAvailabilityService($db))->set(42, true);

        self::assertTrue($state['requestedOnline']);
        self::assertTrue($state['online']);
        self::assertFalse($state['effectiveOnline']);
        self::assertSame(1, $state['availabilityVersion']);
        self::assertCount(1, $statements);
        self::assertStringContainsString('ON CONFLICT (driver_id)', $statements[0][0]);
        self::assertStringContainsString('availability_version + 1', $statements[0][0]);
    }

    public function testIneligibleDriverCannotGoOnline(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchOne')->willReturn(false);
        $db->expects(self::never())->method('executeStatement');

        $this->expectException(\DomainException::class);
        (new DriverAvailabilityService($db))->set(42, true);
    }

    public function testGoingOfflineDoesNotRequireEligibility(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchAssociative')->willReturn([
            'requested_online' => false,
            'effective_online' => false,
            'availability_version' => 7,
            'changed_at' => '2026-09-26 10:00:00+00',
            'last_location_at' => null,
        ]);
        $db->expects(self::never())->method('fetchOne');
        $db->expects(self::once())->method('executeStatement')->willReturn(1);

        self::assertFalse((new DriverAvailabilityService($db))->set(42, false)['online']);
    }

    public function testRepeatedOfflineRequestsAreIdempotentAndServerVersioned(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchAssociative')->willReturnOnConsecutiveCalls(
            [
                'requested_online' => false,
                'effective_online' => false,
                'availability_version' => 8,
                'changed_at' => '2026-09-26 14:30:00+00',
                'last_location_at' => '2026-09-26 14:30:05+00',
            ],
            [
                'requested_online' => false,
                'effective_online' => false,
                'availability_version' => 9,
                'changed_at' => '2026-09-26 14:30:00+00',
                'last_location_at' => '2026-09-26 14:30:05+00',
            ],
        );
        $db->expects(self::never())->method('fetchOne');
        $db->expects(self::exactly(2))->method('executeStatement');

        $service = new DriverAvailabilityService($db);
        $first = $service->set(42, false);
        $second = $service->set(42, false);

        self::assertFalse($first['requestedOnline']);
        self::assertFalse($second['requestedOnline']);
        self::assertSame(8, $first['availabilityVersion']);
        self::assertSame(9, $second['availabilityVersion']);
    }
}
