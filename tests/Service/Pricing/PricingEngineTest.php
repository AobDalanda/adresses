<?php

declare(strict_types=1);

namespace App\Tests\Service\Pricing;

use App\Dto\Pricing\PricingRequest;
use App\Service\Pricing\PricingEngine;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class PricingEngineTest extends TestCase
{
    public function testCalculateUsesDatabaseRuleAndSurcharges(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::exactly(2))
            ->method('fetchAssociative')
            ->willReturnOnConsecutiveCalls(
                ['id' => 7, 'name' => 'Model 2026'],
                ['id' => 9, 'base_price' => 15000, 'price_per_km' => 1500, 'currency' => 'GNF']
            );
        $db->expects(self::once())
            ->method('fetchAllAssociative')
            ->willReturn([
                [
                    'name' => 'Express',
                    'type' => 'percentage',
                    'value' => '10.00',
                    'condition_json' => '{"service_type":"EXPRESS"}',
                ],
                [
                    'name' => 'Longue distance',
                    'type' => 'fixed',
                    'value' => '2000.00',
                    'condition_json' => '{"distance_min":5}',
                ],
            ]);

        $result = (new PricingEngine($db))->calculate(new PricingRequest(
            distanceKm: 7.4,
            durationMinutes: 28,
            serviceType: 'EXPRESS',
            vehicleType: 'MOTO',
            customerType: 'CLIENT',
            zoneId: null,
            date: new \DateTimeImmutable('2026-06-18 12:00:00+00:00')
        ));

        self::assertSame(15000, $result->basePrice);
        self::assertSame(11100, $result->distancePrice);
        self::assertSame(30710, $result->totalPrice);
        self::assertCount(2, $result->surcharges);
        self::assertSame(7, $result->pricingModelId);
        self::assertSame(9, $result->pricingRuleId);
    }

    public function testCalculateUsesGlobalFallbackRuleWhenNoSpecificRuleMatches(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::exactly(2))
            ->method('fetchAssociative')
            ->willReturnOnConsecutiveCalls(
                ['id' => 7, 'name' => 'Model 2026'],
                ['id' => 99, 'base_price' => 5000, 'price_per_km' => 1000, 'currency' => 'GNF']
            );
        $db->expects(self::once())
            ->method('fetchAllAssociative')
            ->willReturn([]);

        $result = (new PricingEngine($db))->calculate(new PricingRequest(
            distanceKm: 12.3,
            durationMinutes: 42,
            serviceType: 'INCONNU',
            vehicleType: 'NON_CONFIGURE',
            customerType: 'CLIENT',
            zoneId: null,
            date: new \DateTimeImmutable('2026-06-18 12:00:00+00:00')
        ));

        self::assertSame(5000, $result->basePrice);
        self::assertSame(12300, $result->distancePrice);
        self::assertSame(17300, $result->totalPrice);
        self::assertSame('GNF', $result->currency);
        self::assertSame(99, $result->pricingRuleId);
    }
}
