<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\NearbyDriverMatcher;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class NearbyDriverMatcherTest extends TestCase
{
    public function testUsesGpsPositionForCountryCompatibilityWhenPrimaryAddressIsMissing(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())
            ->method('fetchAllAssociative')
            ->with(
                self::callback(static fn (string $sql): bool =>
                    str_contains($sql, 'NOT EXISTS (')
                    && str_contains($sql, 'FROM user_address primary_address')
                    && str_contains($sql, 'latest_location.position IS NOT NULL')
                    && str_contains($sql, 'ST_DWithin(')
                    && str_contains($sql, 'CAST(:pickupCountry AS VARCHAR(2)) IS NULL')
                    && str_contains($sql, 'driver_address.country_code = CAST(:pickupCountry AS VARCHAR(2))')
                ),
                self::callback(static fn (array $parameters): bool =>
                    $parameters['pickupCountry'] === 'FR'
                    && $parameters['radiusMeters'] === 1000
                ),
                self::callback(static fn (array $types): bool =>
                    $types['pickupCountry'] === ParameterType::STRING
                    && $types['radiusMeters'] === ParameterType::INTEGER
                    && $types['presenceTtl'] === ParameterType::INTEGER
                ),
            )
            ->willReturn([$this->eligibleDriverRow(countryCompatible: true)]);

        $matcher = new NearbyDriverMatcher($db, new NullLogger());

        self::assertSame([[
            'driverId' => 43,
            'distanceMeters' => 13,
        ]], $matcher->findEligibleDrivers(
            48.039179881549,
            -1.5393593162298,
            'STANDARD',
            'MOTO',
            1000,
            'fr',
        ));
    }

    public function testDifferentPrimaryAddressCountryRemainsIncompatible(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchAllAssociative')
            ->willReturn([$this->eligibleDriverRow(countryCompatible: false)]);

        $matcher = new NearbyDriverMatcher($db, new NullLogger());
        $diagnostics = $matcher->evaluateDrivers(
            48.039179881549,
            -1.5393593162298,
            'STANDARD',
            'MOTO',
            1000,
            'FR',
        );

        self::assertFalse($diagnostics[0]['eligible']);
        self::assertSame('COUNTRY_NOT_COMPATIBLE', $diagnostics[0]['rejectionReason']);
    }

    /** @return array<string, mixed> */
    private function eligibleDriverRow(bool $countryCompatible): array
    {
        return [
            'driver_id' => 43,
            'online' => true,
            'effective_online' => true,
            'location_age_seconds' => 30,
            'accuracy_meters' => 15.0,
            'distance_meters' => 13,
            'service_compatible' => true,
            'vehicle_compatible' => true,
            'country_compatible' => $countryCompatible,
            'account_validated' => true,
        ];
    }
}
