<?php

declare(strict_types=1);

namespace App\Tests\Api\Controller;

use App\Api\Controller\PricingCatalogAction;
use App\Service\JwtAuthService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class PricingCatalogActionTest extends TestCase
{
    public function testCatalogRequiresAuthentication(): void
    {
        $jwt = $this->createMock(JwtAuthService::class);
        $jwt->method('decodeFromRequest')->willReturn(null);

        $response = (new PricingCatalogAction($jwt, $this->createMock(Connection::class)))->__invoke(new Request());

        self::assertSame(401, $response->getStatusCode());
    }

    public function testCatalogReturnsConfiguredTypesAndZones(): void
    {
        $jwt = $this->createMock(JwtAuthService::class);
        $jwt->method('decodeFromRequest')->willReturn(['typ' => 'mobile', 'uid' => 12]);

        $db = $this->createMock(Connection::class);
        $db->expects(self::exactly(3))
            ->method('fetchAllAssociative')
            ->willReturnOnConsecutiveCalls(
                [['code' => 'STANDARD', 'name' => 'Standard', 'description' => null]],
                [['code' => 'MOTO', 'name' => 'Moto', 'description' => null]],
                [['id' => 1, 'name' => 'DEFAULT', 'parentZoneId' => null]]
            );

        $response = (new PricingCatalogAction($jwt, $db))->__invoke(new Request());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            '{"serviceTypes":[{"code":"STANDARD","name":"Standard","description":null}],"vehicleTypes":[{"code":"MOTO","name":"Moto","description":null}],"zones":[{"id":1,"name":"DEFAULT","parentZoneId":null}]}',
            $response->getContent()
        );
    }
}
