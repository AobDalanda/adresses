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
        $db->method('fetchAssociative')->willReturnOnConsecutiveCalls(false, [
            'requested_online' => true,
            'effective_online' => true,
            'availability_version' => 42,
            'changed_at' => '2026-09-26 10:00:00+00',
            'last_location_at' => '2026-09-26 10:00:00+00',
        ]);
        $db->method('executeStatement')->willReturnCallback(
            static function (string $sql, array $params = []) use (&$statements): int {
                $statements[] = [$sql, $params];

                return 1;
            },
        );
        $state = (new DriverAvailabilityService($db))->set(42, true, 42);

        self::assertTrue($state['requestedOnline']);
        self::assertTrue($state['online']);
        self::assertTrue($state['effectiveOnline']);
        self::assertSame(42, $state['availabilityVersion']);
        self::assertCount(1, $statements);
        self::assertStringContainsString('ON CONFLICT (driver_id)', $statements[0][0]);
        self::assertStringContainsString('EXCLUDED.availability_version >', $statements[0][0]);
    }

    public function testIneligibleDriverCannotGoOnline(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchAssociative')->willReturn(false);
        $db->method('fetchOne')->willReturn(false);
        $db->expects(self::never())->method('executeStatement');

        $this->expectException(\DomainException::class);
        (new DriverAvailabilityService($db))->set(42, true, 1);
    }

    public function testGoingOfflineDoesNotRequireEligibility(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchAssociative')->willReturnOnConsecutiveCalls(false, [
            'requested_online' => false,
            'effective_online' => false,
            'availability_version' => 7,
            'changed_at' => '2026-09-26 10:00:00+00',
            'last_location_at' => null,
        ]);
        $db->expects(self::never())->method('fetchOne');
        $db->expects(self::once())->method('executeStatement')->willReturn(1);

        self::assertFalse((new DriverAvailabilityService($db))->set(42, false, 7)['online']);
    }

    public function testOlderOnlineRequestCannotUndoNewerOfflineChoice(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchAssociative')->willReturnOnConsecutiveCalls(
            ['requested_online' => false, 'availability_version' => 42],
            [
                'requested_online' => false,
                'effective_online' => false,
                'availability_version' => 42,
                'changed_at' => '2026-09-26 14:30:00+00',
                'last_location_at' => '2026-09-26 14:30:05+00',
            ],
        );
        $db->expects(self::never())->method('fetchOne');
        $db->expects(self::never())->method('executeStatement');

        $state = (new DriverAvailabilityService($db))->set(42, true, 41);

        self::assertFalse($state['requestedOnline']);
        self::assertFalse($state['effectiveOnline']);
        self::assertSame(42, $state['availabilityVersion']);
    }

    public function testOfflineWinsWhenConflictingRequestsHaveTheSameVersion(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchAssociative')->willReturnOnConsecutiveCalls(
            ['requested_online' => true, 'availability_version' => 42],
            [
                'requested_online' => false,
                'effective_online' => false,
                'availability_version' => 42,
                'changed_at' => '2026-09-26 14:30:00+00',
                'last_location_at' => null,
            ],
        );
        $db->expects(self::never())->method('fetchOne');
        $db->expects(self::once())->method('executeStatement');

        $state = (new DriverAvailabilityService($db))->set(42, false, 42);

        self::assertFalse($state['requestedOnline']);
    }
}
