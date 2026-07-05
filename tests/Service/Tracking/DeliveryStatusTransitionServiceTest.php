<?php

declare(strict_types=1);

namespace App\Tests\Service\Tracking;

use App\Service\Tracking\DeliveryStatusTransitionService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class DeliveryStatusTransitionServiceTest extends TestCase
{
    public function testDeliveredTransitionPersistsProofAndConsumesAssets(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('transactional')->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $db->expects(self::exactly(4))
            ->method('fetchAssociative')
            ->willReturnOnConsecutiveCalls(
                [
                    'id' => 42,
                    'public_id' => '01975aa9-df9c-7b25-b797-6b1ca912e68f',
                    'status' => 'IN_TRANSIT',
                    'completed_at' => null,
                    'signature_required' => true,
                ],
                ['id' => 501],
                ['id' => 502],
                [
                    'public_id' => '01975aa9-df9c-7b25-b797-6b1ca912e68f',
                    'status' => 'DELIVERED',
                    'completed_at' => '2026-07-05T09:32:00+00:00',
                ],
            );

        $executed = [];
        $db->expects(self::exactly(4))
            ->method('executeStatement')
            ->willReturnCallback(static function (string $sql, array $params = []) use (&$executed): int {
                $executed[] = [$sql, $params];

                return 1;
            });

        $result = (new DeliveryStatusTransitionService($db))->transition(
            '01975aa9-df9c-7b25-b797-6b1ca912e68f',
            15,
            'DELIVERED',
            null,
            [
                'receptionCode' => '456789',
                'recipientName' => 'M. Camara',
                'recipientSignatureAssetId' => 501,
                'deliveryPhotoAssetId' => 502,
            ],
        );

        self::assertSame('DELIVERED', $result['status']);
        self::assertSame('2026-07-05T09:32:00+00:00', $result['completedAt']);
        self::assertCount(4, $executed);
        self::assertStringContainsString('UPDATE uploaded_asset asset', $executed[0][0]);
        self::assertSame(501, $executed[0][1]['assetId']);
        self::assertStringContainsString('UPDATE uploaded_asset asset', $executed[1][0]);
        self::assertSame(502, $executed[1][1]['assetId']);
        self::assertStringContainsString('INSERT INTO delivery_proof', $executed[2][0]);
        self::assertSame('456789', $executed[2][1]['receptionCode']);
        self::assertStringContainsString('INSERT INTO delivery_status_history', $executed[3][0]);
    }

    public function testDeliveredTransitionRejectsMissingSignatureWhenRequired(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('transactional')->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $db->expects(self::once())
            ->method('fetchAssociative')
            ->willReturn([
                'id' => 42,
                'public_id' => '01975aa9-df9c-7b25-b797-6b1ca912e68f',
                'status' => 'IN_TRANSIT',
                'completed_at' => null,
                'signature_required' => true,
            ]);
        $db->expects(self::never())->method('executeStatement');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('recipientSignatureAssetId est requis');

        (new DeliveryStatusTransitionService($db))->transition(
            '01975aa9-df9c-7b25-b797-6b1ca912e68f',
            15,
            'DELIVERED',
            null,
            ['deliveryPhotoAssetId' => 502],
        );
    }
}
