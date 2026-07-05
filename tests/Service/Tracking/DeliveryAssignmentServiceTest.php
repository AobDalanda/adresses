<?php

declare(strict_types=1);

namespace App\Tests\Service\Tracking;

use App\Service\Tracking\DeliveryAssignmentService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class DeliveryAssignmentServiceTest extends TestCase
{
    public function testDriverClaimsAvailableDeliveryAtomically(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('transactional')->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $db->expects(self::once())
            ->method('fetchAssociative')
            ->with(
                self::callback(static function (string $sql): bool {
                    self::assertStringContainsString("status IN ('QUOTED', 'CONFIRMED')", $sql);
                    self::assertStringContainsString('assigned_driver_id IS NULL', $sql);
                    self::assertStringContainsString("status = 'ASSIGNED'", $sql);

                    return true;
                }),
                [
                    'driverId' => 15,
                    'publicId' => '01975aa9-df9c-7b25-b797-6b1ca912e68f',
                ],
            )
            ->willReturn([
                'id' => '42',
                'public_id' => '01975aa9-df9c-7b25-b797-6b1ca912e68f',
                'status' => 'ASSIGNED',
                'assigned_at' => '2026-06-30 21:15:00+00',
            ]);
        $db->expects(self::once())->method('executeStatement')->willReturn(1);

        $result = (new DeliveryAssignmentService($db))->accept(
            '01975aa9-df9c-7b25-b797-6b1ca912e68f',
            15,
        );

        self::assertSame(15, $result['driverId']);
        self::assertSame('ASSIGNED', $result['status']);
        self::assertSame('En cours', $result['statusLabel']);
        self::assertSame('in_progress', $result['statusGroup']);
    }

    public function testAlreadyClaimedDeliveryIsRejected(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('transactional')->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $db->method('fetchAssociative')->willReturn(false);
        $db->expects(self::never())->method('executeStatement');

        $this->expectException(\DomainException::class);
        (new DeliveryAssignmentService($db))->accept('unavailable', 15);
    }
}
