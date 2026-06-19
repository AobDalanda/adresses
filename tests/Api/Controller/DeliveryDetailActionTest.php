<?php

declare(strict_types=1);

namespace App\Tests\Api\Controller;

use App\Api\Controller\DeliveryDetailAction;
use App\Service\DeliveryOverviewService;
use App\Service\JwtAuthService;
use App\Service\SupabaseStorageClient;
use App\Service\UserAccountAssetUrlResolver;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class DeliveryDetailActionTest extends TestCase
{
    public function testDetailRequiresAuthentication(): void
    {
        $jwt = $this->createMock(JwtAuthService::class);
        $jwt->method('decodeFromRequest')->willReturn(null);

        $response = (new DeliveryDetailAction($jwt, $this->service($this->createMock(Connection::class))))->__invoke(new Request(), 'uuid-1');

        self::assertSame(401, $response->getStatusCode());
    }

    public function testDetailReturnsOrderPayload(): void
    {
        $jwt = $this->createMock(JwtAuthService::class);
        $jwt->method('decodeFromRequest')->willReturn(['typ' => 'mobile', 'uid' => 12]);

        $db = $this->createMock(Connection::class);
        $db->expects(self::once())
            ->method('fetchAssociative')
            ->willReturn([
                'id' => 1,
                'public_id' => 'uuid-1',
                'status' => 'DELIVERED',
                'scheduled_at' => null,
                'created_at' => '2026-06-20T09:00:00+00:00',
                'notes' => null,
                'recipient_name' => null,
                'recipient_phone' => null,
                'pickup_address_id' => 3,
                'pickup_display_label' => 'Bureau',
                'dropoff_address_id' => 9,
                'dropoff_display_label' => 'Maison',
                'package_description' => null,
                'declared_value_amount' => null,
                'declared_value_currency' => null,
                'weight_kg' => null,
                'length_cm' => null,
                'width_cm' => null,
                'height_cm' => null,
                'fragile' => false,
                'photo_asset_id' => null,
                'distance_km' => 7.4,
                'duration_minutes' => 28,
                'base_amount' => 2000,
                'surcharge_amount' => 500,
                'total_amount' => 2500,
                'currency' => 'GNF',
            ]);
        $db->expects(self::once())
            ->method('fetchAllAssociative')
            ->willReturn([]);

        $response = (new DeliveryDetailAction($jwt, $this->service($db)))->__invoke(new Request(), 'uuid-1');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('"orderNumber":"CMD-001"', (string) $response->getContent());
    }

    private function service(Connection $db): DeliveryOverviewService
    {
        $storage = $this->createMock(SupabaseStorageClient::class);
        $resolver = new UserAccountAssetUrlResolver($storage);

        return new DeliveryOverviewService($db, $resolver);
    }
}
