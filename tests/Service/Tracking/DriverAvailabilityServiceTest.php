<?php

declare(strict_types=1);

namespace App\Tests\Service\Tracking;

use App\Exception\DriverNotAuthorizedException;
use App\Exception\DriverProfileIncompleteException;
use App\Exception\DriverProfileNotFoundException;
use App\Service\Tracking\DriverAvailabilityService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class DriverAvailabilityServiceTest extends TestCase
{
    public function testGetReturnsStableOfflineStateForDriverWithoutAvailabilityRow(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchAssociative')->willReturn($this->validProfileRow() + [
            'requested_online' => false,
            'effective_online' => false,
            'availability_version' => 0,
            'changed_at' => null,
            'last_location_at' => null,
        ]);

        self::assertSame(
            [
                'requestedOnline' => false,
                'online' => false,
                'effectiveOnline' => false,
                'availabilityVersion' => 0,
                'changedAt' => null,
                'lastLocationAt' => null,
            ],
            (new DriverAvailabilityService($db))->get(43),
        );
    }

    public function testAccount43CanRequestOnlineWithoutLocationOrAvailabilityVersion(): void
    {
        $statements = [];
        $db = $this->createMock(Connection::class);
        $db->method('fetchAssociative')->willReturnOnConsecutiveCalls(
            $this->validProfileRow(),
            $this->validProfileRow() + [
                'requested_online' => true,
                'effective_online' => false,
                'availability_version' => 1,
                'changed_at' => '2026-09-26 17:00:00+00',
                'last_location_at' => null,
            ],
        );
        $db->method('executeStatement')->willReturnCallback(
            static function (string $sql, array $params = []) use (&$statements): int {
                $statements[] = [$sql, $params];

                return 1;
            },
        );

        $state = (new DriverAvailabilityService($db))->set(43, true);

        self::assertTrue($state['requestedOnline']);
        self::assertTrue($state['online']);
        self::assertFalse($state['effectiveOnline']);
        self::assertSame(1, $state['availabilityVersion']);
        self::assertCount(1, $statements);
        self::assertSame(['driverId' => 43, 'online' => 'true'], $statements[0][1]);
    }

    public function testDriverCanGoOfflineImmediately(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchAssociative')->willReturnOnConsecutiveCalls(
            $this->validProfileRow(),
            $this->validProfileRow() + [
                'requested_online' => false,
                'effective_online' => false,
                'availability_version' => 8,
                'changed_at' => '2026-09-26 17:01:00+00',
                'last_location_at' => '2026-09-26 17:00:59+00',
            ],
        );
        $db->expects(self::once())->method('executeStatement');

        $state = (new DriverAvailabilityService($db))->set(43, false);

        self::assertFalse($state['requestedOnline']);
        self::assertFalse($state['effectiveOnline']);
    }

    public function testApprovedLegacyProviderWithoutCanonicalDriverProfileIsReportedAsMissing(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchAssociative')->willReturn([
            'profile_id' => null,
            'legacy_application_status' => 'APPROVED',
        ]);

        $this->expectException(DriverProfileNotFoundException::class);
        (new DriverAvailabilityService($db))->set(43, true);
    }

    public function testIncompleteDriverProfileIsRejectedExplicitly(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchAssociative')->willReturn(array_replace($this->validProfileRow(), [
            'authorization_id' => null,
        ]));

        $this->expectException(DriverProfileIncompleteException::class);
        (new DriverAvailabilityService($db))->set(43, true);
    }

    public function testNonProviderUserIsForbidden(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchAssociative')->willReturn([
            'profile_id' => null,
            'legacy_application_status' => null,
        ]);

        $this->expectException(DriverNotAuthorizedException::class);
        (new DriverAvailabilityService($db))->set(12, true);
    }

    public function testQueriesDoNotUsePostgresqlAuthorizationKeywordAsAlias(): void
    {
        $queries = [];
        $db = $this->createMock(Connection::class);
        $db->method('fetchAssociative')->willReturnCallback(
            function (string $sql) use (&$queries): array {
                $queries[] = $sql;

                return $this->validProfileRow() + [
                    'requested_online' => false,
                    'effective_online' => false,
                    'availability_version' => 0,
                    'changed_at' => null,
                    'last_location_at' => null,
                ];
            },
        );

        (new DriverAvailabilityService($db))->get(43);

        self::assertNotEmpty($queries);
        self::assertStringNotContainsString('provider_authorization authorization', $queries[0]);
        self::assertStringNotContainsString('authorization.', $queries[0]);
        self::assertStringContainsString('provider_auth', $queries[0]);
    }

    /** @return array<string, mixed> */
    private function validProfileRow(): array
    {
        return [
            'profile_id' => 2,
            'can_deliver' => true,
            'validation_status' => 'approved',
            'authorization_id' => 2,
            'authorization_status' => 'ACTIVE',
            'authorization_can_deliver' => true,
            'legacy_application_status' => 'APPROVED',
        ];
    }
}
