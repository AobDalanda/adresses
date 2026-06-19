<?php

declare(strict_types=1);

namespace App\Tests\Api\Controller;

use App\Api\Controller\DeliveryListAction;
use App\Service\DeliveryOverviewService;
use App\Service\JwtAuthService;
use App\Service\SupabaseStorageClient;
use App\Service\UserAccountAssetUrlResolver;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class DeliveryListActionTest extends TestCase
{
    public function testListRequiresAuthentication(): void
    {
        $jwt = $this->createMock(JwtAuthService::class);
        $jwt->method('decodeFromRequest')->willReturn(null);

        $response = (new DeliveryListAction($jwt, $this->service($this->createMock(Connection::class))))->__invoke(new Request());

        self::assertSame(401, $response->getStatusCode());
    }

    public function testListReturnsPaginatedOrders(): void
    {
        $jwt = $this->createMock(JwtAuthService::class);
        $jwt->method('decodeFromRequest')->willReturn(['typ' => 'mobile', 'uid' => 12]);

        $db = $this->createMock(Connection::class);
        $db->expects(self::once())
            ->method('fetchAllAssociative')
            ->willReturn([
                [
                    'id' => 1,
                    'public_id' => 'uuid-1',
                    'status' => 'IN_TRANSIT',
                    'scheduled_at' => '2026-06-20T10:30:00+00:00',
                    'created_at' => '2026-06-20T09:00:00+00:00',
                    'dropoff_address_id' => 9,
                    'dropoff_display_label' => 'Maison',
                    'pickup_address_id' => 3,
                    'pickup_display_label' => 'Bureau',
                    'total_amount' => 2500,
                    'currency' => 'GNF',
                ],
            ]);
        $db->expects(self::once())
            ->method('fetchOne')
            ->willReturn(21);

        $request = new Request(query: ['status' => 'in_progress', 'page' => '2', 'perPage' => '10']);
        $response = (new DeliveryListAction($jwt, $this->service($db)))->__invoke($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('"orderNumber":"CMD-001"', (string) $response->getContent());
        self::assertStringContainsString('"status":"in_progress"', (string) $response->getContent());
    }

    private function service(Connection $db): DeliveryOverviewService
    {
        $storage = $this->createMock(SupabaseStorageClient::class);
        $resolver = new UserAccountAssetUrlResolver($storage);

        return new DeliveryOverviewService($db, $resolver);
    }
}
