<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\PaymentService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class PaymentServiceTest extends TestCase
{
    public function testRecordWebhookEventUpdatesDeliveryPaymentWhenPayloadTargetsDelivery(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())
            ->method('fetchOne')
            ->with(
                self::stringContains('SELECT id FROM delivery_order'),
                ['publicId' => '01975aa9-df9c-7b25-b797-6b1ca912e68f']
            )
            ->willReturn(42);

        $executed = [];
        $db->expects(self::exactly(2))
            ->method('executeStatement')
            ->willReturnCallback(static function (string $sql, array $params = []) use (&$executed): int {
                $executed[] = [$sql, $params];

                return 1;
            });

        $service = new PaymentService($db, []);
        $service->recordWebhookEvent('orange_money', 'prov-ref-1', [
            'deliveryId' => '01975aa9-df9c-7b25-b797-6b1ca912e68f',
            'paymentStatus' => 'paid',
            'paymentMethod' => 'orange_money',
            'paidAt' => '2026-07-05T11:03:00+00:00',
        ], 'RECEIVED');

        self::assertCount(2, $executed);
        self::assertStringContainsString('UPDATE delivery_payment', $executed[0][0]);
        self::assertSame('PAID', $executed[0][1]['status']);
        self::assertSame('orange_money', $executed[0][1]['paymentMethod']);
        self::assertSame('prov-ref-1', $executed[0][1]['providerReference']);
        self::assertSame(42, $executed[0][1]['deliveryOrderId']);
        self::assertStringContainsString('INSERT INTO payment_event', $executed[1][0]);
        self::assertSame('DELIVERY_ORDER', $executed[1][1]['ownerType']);
        self::assertSame(42, $executed[1][1]['ownerId']);
    }

    public function testRecordWebhookEventKeepsUnknownOwnerForNonDeliveryPayload(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('fetchOne');
        $db->expects(self::once())
            ->method('executeStatement')
            ->with(
                self::stringContains('INSERT INTO payment_event'),
                self::callback(static fn (array $params): bool =>
                    $params['ownerType'] === 'UNKNOWN' && $params['ownerId'] === 0
                )
            )
            ->willReturn(1);

        $service = new PaymentService($db, []);
        $service->recordWebhookEvent('orange_money', 'prov-ref-1', [
            'status' => 'received',
        ], 'RECEIVED');
    }
}
