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
            ['online' => false, 'effectiveOnline' => false, 'changedAt' => null, 'lastHeartbeatAt' => null],
            (new DriverAvailabilityService($db))->get(42),
        );
    }

    public function testEligibleDriverCanGoOnlineAndRecentLocationActivatesHeartbeat(): void
    {
        $statements = [];
        $db = $this->createMock(Connection::class);
        $db->method('fetchOne')->willReturn(true);
        $db->method('executeStatement')->willReturnCallback(
            static function (string $sql, array $params = []) use (&$statements): int {
                $statements[] = [$sql, $params];

                return 1;
            },
        );
        $db->method('fetchAssociative')->willReturn([
            'is_online' => true,
            'effective_online' => true,
            'changed_at' => '2026-09-26 10:00:00+00',
            'last_heartbeat_at' => '2026-09-26 10:00:00+00',
        ]);

        $state = (new DriverAvailabilityService($db))->set(42, true);

        self::assertTrue($state['online']);
        self::assertTrue($state['effectiveOnline']);
        self::assertCount(2, $statements);
        self::assertStringContainsString('ON CONFLICT (driver_id)', $statements[0][0]);
        self::assertStringContainsString('FROM driver_location', $statements[1][0]);
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
        $db->expects(self::never())->method('fetchOne');
        $db->expects(self::once())->method('executeStatement')->willReturn(1);
        $db->method('fetchAssociative')->willReturn([
            'is_online' => false,
            'effective_online' => false,
            'changed_at' => '2026-09-26 10:00:00+00',
            'last_heartbeat_at' => null,
        ]);

        self::assertFalse((new DriverAvailabilityService($db))->set(42, false)['online']);
    }
}
