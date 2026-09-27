<?php
declare(strict_types=1);
namespace App\Tests\Service\Tracking;

use App\Exception\DeliveryNotAvailableException;
use App\Repository\DeliveryOrderRepository;
use App\Service\DeliveryOfferAccessChecker;
use App\Service\DriverAccessChecker;
use App\Service\NearbyDriverMatcher;
use App\Service\Tracking\DeliveryAssignmentService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class DeliveryAssignmentServiceTest extends TestCase
{
    public function testDriverClaimsAvailableDeliveryUnderPessimisticLock(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('transactional')->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $calls = 0;
        $db->method('fetchAssociative')->willReturnCallback(function (string $sql) use (&$calls): array|false {
            ++$calls;
            if ($calls === 1) {
                self::assertStringContainsString('FOR UPDATE OF delivery', $sql);
                return $this->offer();
            }
            self::assertStringContainsString("status = 'QUOTED'", $sql);
            self::assertStringContainsString('assigned_driver_id IS NULL', $sql);
            return ['id' => '42', 'public_id' => '01975aa9-df9c-7b25-b797-6b1ca912e68f', 'status' => 'ASSIGNED', 'assigned_at' => '2026-06-30 21:15:00+00'];
        });
        $db->method('fetchOne')->willReturn(1);
        $db->method('fetchAllAssociative')->willReturn([$this->eligibleDriver()]);
        $db->expects(self::once())->method('executeStatement')->willReturn(1);

        $result = $this->service($db)->accept('01975aa9-df9c-7b25-b797-6b1ca912e68f', 15);
        self::assertSame(15, $result['driverId']);
        self::assertSame('ASSIGNED', $result['status']);
    }

    public function testAlreadyClaimedDeliveryIsRejected(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('transactional')->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $db->method('fetchAssociative')->willReturn($this->offer(['assigned_driver_id' => '9']));
        $db->method('fetchOne')->willReturn(1);
        $db->method('fetchAllAssociative')->willReturn([$this->eligibleDriver()]);
        $db->expects(self::never())->method('executeStatement');

        $this->expectException(DeliveryNotAvailableException::class);
        $this->service($db)->accept('unavailable', 15);
    }

    private function service(Connection $db): DeliveryAssignmentService
    {
        $access = new DeliveryOfferAccessChecker(
            new DriverAccessChecker($db),
            new NearbyDriverMatcher($db, new NullLogger(), 180, 100),
            1000,
        );
        return new DeliveryAssignmentService($db, new DeliveryOrderRepository($db), $access);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function offer(array $overrides = []): array
    {
        return $overrides + [
            'id' => '42', 'public_id' => '01975aa9-df9c-7b25-b797-6b1ca912e68f',
            'status' => 'QUOTED', 'assigned_driver_id' => null,
            'pickup_latitude' => 9.6, 'pickup_longitude' => -13.6,
            'pickup_country_code' => 'GN', 'service_type_code' => 'DELIVERY',
            'vehicle_type_code' => 'MOTORCYCLE',
        ];
    }

    /** @return array<string, mixed> */
    private function eligibleDriver(): array
    {
        return [
            'driver_id' => 15, 'online' => true, 'effective_online' => true,
            'location_age_seconds' => 10, 'accuracy_meters' => 5,
            'distance_meters' => 120, 'service_compatible' => true,
            'vehicle_compatible' => true, 'country_compatible' => true,
            'account_validated' => true,
        ];
    }
}
