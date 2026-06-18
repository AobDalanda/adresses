<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\DeliveryQuoteService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class DeliveryQuoteServiceTest extends TestCase
{
    public function testQuoteByNamedAddressesCalculatesDeliveryInformation(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::exactly(2))
            ->method('fetchAssociative')
            ->willReturnOnConsecutiveCalls(
                [
                    'address_id' => 10,
                    'address_name' => 'Domicile',
                    'latitude' => 9.6412,
                    'longitude' => -13.5784,
                    'user_id' => 12,
                    'user_name' => 'Aissatou Barry',
                    'user_phone' => '+224620000001',
                ],
                [
                    'address_id' => 22,
                    'address_name' => 'Bureau',
                    'latitude' => 9.6900,
                    'longitude' => -13.5200,
                    'user_id' => 34,
                    'user_name' => 'Mamadou Diallo',
                    'user_phone' => '+224620123456',
                ]
            );

        $quote = (new DeliveryQuoteService($db))->quote(
            ['addressName' => 'Domicile', 'userIdentifier' => 'USR_12'],
            ['addressName' => 'Bureau', 'userIdentifier' => 'USR_34']
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
        $db->expects(self::exactly(2))
            ->method('fetchAssociative')
            ->willReturnOnConsecutiveCalls(
                [
                    'address_id' => 10,
                    'address_name' => 'Domicile',
                    'latitude' => 9.6412,
                    'longitude' => -13.5784,
                    'user_id' => 12,
                    'user_name' => 'Aissatou Barry',
                    'user_phone' => '+224620000001',
                ],
                [
                    'address_id' => 22,
                    'address_name' => 'Bureau',
                    'latitude' => 9.6900,
                    'longitude' => -13.5200,
                    'user_id' => 34,
                    'user_name' => 'Mamadou Diallo',
                    'user_phone' => '+224620123456',
                ]
            );

        $quote = (new DeliveryQuoteService($db))->quote(
            ['addressName' => 'Domicile', 'userIdentifier' => '12'],
            'ADR_TOKEN'
        );

        self::assertSame('USR_34', $quote['recipient']['id']);
    }

    public function testQuoteAcceptsIntegerUserIdentifier(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::exactly(2))
            ->method('fetchAssociative')
            ->willReturnOnConsecutiveCalls(
                [
                    'address_id' => 10,
                    'address_name' => 'Domicile',
                    'latitude' => 9.6412,
                    'longitude' => -13.5784,
                    'user_id' => 12,
                    'user_name' => 'Aissatou Barry',
                    'user_phone' => '+224620000001',
                ],
                [
                    'address_id' => 22,
                    'address_name' => 'Bureau',
                    'latitude' => 9.6900,
                    'longitude' => -13.5200,
                    'user_id' => 34,
                    'user_name' => 'Mamadou Diallo',
                    'user_phone' => '+224620123456',
                ]
            );

        $quote = (new DeliveryQuoteService($db))->quote(
            ['addressName' => 'Domicile', 'userIdentifier' => 12],
            ['addressName' => 'Bureau', 'userIdentifier' => 34]
        );

        self::assertSame('USR_34', $quote['recipient']['id']);
    }

    public function testQuoteAcceptsPhoneUserIdentifier(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::exactly(2))
            ->method('fetchOne')
            ->willReturnOnConsecutiveCalls(12, 34);
        $db->expects(self::exactly(2))
            ->method('fetchAssociative')
            ->willReturnOnConsecutiveCalls(
                [
                    'address_id' => 10,
                    'address_name' => 'Domicile',
                    'latitude' => 9.6412,
                    'longitude' => -13.5784,
                    'user_id' => 12,
                    'user_name' => 'Aissatou Barry',
                    'user_phone' => '224620000001',
                ],
                [
                    'address_id' => 22,
                    'address_name' => 'Bureau',
                    'latitude' => 9.6900,
                    'longitude' => -13.5200,
                    'user_id' => 34,
                    'user_name' => 'Mamadou Diallo',
                    'user_phone' => '224620123456',
                ]
            );

        $quote = (new DeliveryQuoteService($db))->quote(
            ['addressName' => 'Domicile', 'userIdentifier' => '+224 620 000 001'],
            ['addressName' => 'Bureau', 'userIdentifier' => '+224 620 123 456']
        );

        self::assertSame('USR_34', $quote['recipient']['id']);
    }

    public function testQuoteRejectsInvalidUserIdentifier(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('userIdentifier invalide.');

        (new DeliveryQuoteService($this->createMock(Connection::class)))->quote(
            ['addressName' => 'Domicile', 'userIdentifier' => 'bad'],
            'ADR_TOKEN'
        );
    }
}
