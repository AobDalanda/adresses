<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\Pricing\PricingResult;
use App\Dto\Pricing\PricingRequest;
use App\Service\DeliveryQuoteService;
use App\Service\Pricing\PricingEngine;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class DeliveryQuoteServiceTest extends TestCase
{
    public function testQuoteByNamedAddressesCalculatesDeliveryInformation(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())
            ->method('fetchOne')
            ->with('SELECT account_type FROM user_account WHERE id = :userId LIMIT 1', ['userId' => 12])
            ->willReturn('business');
        $db->expects(self::exactly(2))
            ->method('fetchAssociative')
            ->willReturnOnConsecutiveCalls(
                [
                    'address_id' => 10,
                    'address_name' => 'Domicile',
                    'latitude' => 9.6412,
                    'longitude' => -13.5784,
                    'zone_admin_area_id' => null,
                    'zone_name' => null,
                    'user_id' => 12,
                    'user_name' => 'Aissatou Barry',
                    'user_phone' => '+224620000001',
                ],
                [
                    'address_id' => 22,
                    'address_name' => 'Bureau',
                    'latitude' => 9.6900,
                    'longitude' => -13.5200,
                    'zone_admin_area_id' => null,
                    'zone_name' => null,
                    'user_id' => 34,
                    'user_name' => 'Mamadou Diallo',
                    'user_phone' => '+224620123456',
                ]
            );

        $quote = $this->service($db)->quote(
            ['addressName' => 'Domicile', 'userIdentifier' => 'USR_12'],
            ['addressName' => 'Bureau', 'userIdentifier' => 'USR_34'],
            requesterUserId: 12
        );

        self::assertSame(['id' => 'USR_34', 'firstName' => 'Mamadou', 'lastName' => 'Diallo', 'phone' => '+224620123456'], $quote['recipient']);
        self::assertSame(['latitude' => 9.6412, 'longitude' => -13.5784], $quote['departure']);
        self::assertSame(['latitude' => 9.69, 'longitude' => -13.52], $quote['destination']);
        self::assertGreaterThan(0, $quote['distanceKm']);
        self::assertGreaterThan(0, $quote['durationMinutes']);
        self::assertGreaterThanOrEqual(15000, $quote['deliveryCost']);
        self::assertSame('GNF', $quote['currency']);
    }

    public function testQuoteCanResolveDestinationByQrToken(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())
            ->method('fetchOne')
            ->with('SELECT account_type FROM user_account WHERE id = :userId LIMIT 1', ['userId' => 12])
            ->willReturn('client');
        $db->expects(self::exactly(2))
            ->method('fetchAssociative')
            ->willReturnOnConsecutiveCalls(
                [
                    'address_id' => 10,
                    'address_name' => 'Domicile',
                    'latitude' => 9.6412,
                    'longitude' => -13.5784,
                    'zone_admin_area_id' => null,
                    'zone_name' => null,
                    'user_id' => 12,
                    'user_name' => 'Aissatou Barry',
                    'user_phone' => '+224620000001',
                ],
                [
                    'address_id' => 22,
                    'address_name' => 'Bureau',
                    'latitude' => 9.6900,
                    'longitude' => -13.5200,
                    'zone_admin_area_id' => null,
                    'zone_name' => null,
                    'user_id' => 34,
                    'user_name' => 'Mamadou Diallo',
                    'user_phone' => '+224620123456',
                ]
            );

        $quote = $this->service($db)->quote(
            ['addressName' => 'Domicile', 'userIdentifier' => '12'],
            'ADR_TOKEN',
            requesterUserId: 12
        );

        self::assertSame('USR_34', $quote['recipient']['id']);
    }

    public function testQuoteAcceptsIntegerUserIdentifier(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())
            ->method('fetchOne')
            ->with('SELECT account_type FROM user_account WHERE id = :userId LIMIT 1', ['userId' => 12])
            ->willReturn('client');
        $db->expects(self::exactly(2))
            ->method('fetchAssociative')
            ->willReturnOnConsecutiveCalls(
                [
                    'address_id' => 10,
                    'address_name' => 'Domicile',
                    'latitude' => 9.6412,
                    'longitude' => -13.5784,
                    'zone_admin_area_id' => null,
                    'zone_name' => null,
                    'user_id' => 12,
                    'user_name' => 'Aissatou Barry',
                    'user_phone' => '+224620000001',
                ],
                [
                    'address_id' => 22,
                    'address_name' => 'Bureau',
                    'latitude' => 9.6900,
                    'longitude' => -13.5200,
                    'zone_admin_area_id' => null,
                    'zone_name' => null,
                    'user_id' => 34,
                    'user_name' => 'Mamadou Diallo',
                    'user_phone' => '+224620123456',
                ]
            );

        $quote = $this->service($db)->quote(
            ['addressName' => 'Domicile', 'userIdentifier' => 12],
            ['addressName' => 'Bureau', 'userIdentifier' => 34],
            requesterUserId: 12
        );

        self::assertSame('USR_34', $quote['recipient']['id']);
    }

    public function testQuoteAcceptsPhoneUserIdentifier(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::exactly(3))
            ->method('fetchOne')
            ->willReturnCallback(function (string $query, array $params) {
                if (str_contains($query, 'SELECT account_type')) {
                    return 'provider';
                }

                return $params['phone'] === '224620000001' ? 12 : 34;
            });
        $db->expects(self::exactly(2))
            ->method('fetchAssociative')
            ->willReturnOnConsecutiveCalls(
                [
                    'address_id' => 10,
                    'address_name' => 'Domicile',
                    'latitude' => 9.6412,
                    'longitude' => -13.5784,
                    'zone_admin_area_id' => null,
                    'zone_name' => null,
                    'user_id' => 12,
                    'user_name' => 'Aissatou Barry',
                    'user_phone' => '224620000001',
                ],
                [
                    'address_id' => 22,
                    'address_name' => 'Bureau',
                    'latitude' => 9.6900,
                    'longitude' => -13.5200,
                    'zone_admin_area_id' => null,
                    'zone_name' => null,
                    'user_id' => 34,
                    'user_name' => 'Mamadou Diallo',
                    'user_phone' => '224620123456',
                ]
            );

        $quote = $this->service($db)->quote(
            ['addressName' => 'Domicile', 'userIdentifier' => '+224 620 000 001'],
            ['addressName' => 'Bureau', 'userIdentifier' => '+224 620 123 456'],
            requesterUserId: 12
        );

        self::assertSame('USR_34', $quote['recipient']['id']);
    }

    public function testQuoteSetsEstimatedDistanceToZeroWhenDepartureAndDestinationAreSameAddress(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())
            ->method('fetchOne')
            ->with('SELECT account_type FROM user_account WHERE id = :userId LIMIT 1', ['userId' => 12])
            ->willReturn('client');
        $db->expects(self::exactly(2))
            ->method('fetchAssociative')
            ->willReturnOnConsecutiveCalls(
                [
                    'address_id' => 10,
                    'address_name' => 'Domicile',
                    'latitude' => 9.6412,
                    'longitude' => -13.5784,
                    'zone_admin_area_id' => null,
                    'zone_name' => null,
                    'user_id' => 12,
                    'user_name' => 'Aissatou Barry',
                    'user_phone' => '+224620000001',
                ],
                [
                    'address_id' => 10,
                    'address_name' => 'Domicile',
                    'latitude' => 9.6412,
                    'longitude' => -13.5784,
                    'zone_admin_area_id' => null,
                    'zone_name' => null,
                    'user_id' => 12,
                    'user_name' => 'Aissatou Barry',
                    'user_phone' => '+224620000001',
                ]
            );

        $quote = $this->service($db)->quote(
            ['addressName' => 'Domicile', 'userIdentifier' => 'USR_12'],
            ['addressName' => 'Domicile', 'userIdentifier' => 'USR_12'],
            requesterUserId: 12
        );

        self::assertSame(0.0, $quote['distanceKm']);
    }

    public function testQuoteRejectsInvalidUserIdentifier(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('userIdentifier invalide.');

        $this->service($this->createMock(Connection::class))->quote(
            ['addressName' => 'Domicile', 'userIdentifier' => 'bad'],
            'ADR_TOKEN'
        );
    }

    private function service(Connection $db): DeliveryQuoteService
    {
        $pricing = $this->createMock(PricingEngine::class);
        $pricing->method('calculate')->willReturnCallback(function (PricingRequest $request) {
            self::assertContains($request->customerType, ['BUSINESS', 'CLIENT', 'PROVIDER']);
            self::assertGreaterThanOrEqual(0, $request->distanceKm);

            return new PricingResult(
                distance: round($request->distanceKm, 1),
                duration: $request->durationMinutes,
                basePrice: 15000,
                distancePrice: (int) round($request->distanceKm * 1000),
                surcharges: [],
                totalPrice: 15000 + (int) round($request->distanceKm * 1000),
                currency: 'GNF',
                pricingModelId: 1,
                pricingRuleId: 2
            );
        });

        return new DeliveryQuoteService($db, $pricing);
    }
}
