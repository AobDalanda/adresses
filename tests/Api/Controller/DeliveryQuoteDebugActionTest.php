<?php

declare(strict_types=1);

namespace App\Tests\Api\Controller;

use App\Api\Controller\DeliveryQuoteDebugAction;
use App\Service\JwtAuthService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class DeliveryQuoteDebugActionTest extends TestCase
{
    public function testDebugRequiresAuthentication(): void
    {
        $jwt = $this->createMock(JwtAuthService::class);
        $jwt->method('decodeFromRequest')->willReturn(null);

        $response = (new DeliveryQuoteDebugAction($jwt))->__invoke(new Request());

        self::assertSame(401, $response->getStatusCode());
    }

    public function testDebugReturnsRequestDetails(): void
    {
        $jwt = $this->createMock(JwtAuthService::class);
        $jwt->method('decodeFromRequest')->willReturn(['typ' => 'mobile', 'uid' => 12]);

        $request = new Request(
            attributes: [
                '_route' => 'app_delivery_quote_debug',
            ],
            server: [
                'REQUEST_URI' => '/api/v1/deliveries/quote/debug?debug=1',
                'PATH_INFO' => '/api/v1/deliveries/quote/debug',
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer token',
            ],
            content: '{"departure":{"addressName":"Domicile","userIdentifier":"USR_12"},"destination":"ADR_TOKEN","serviceType":"STANDARD","vehicleType":"MOTO"}'
        );

        $response = (new DeliveryQuoteDebugAction($jwt))->__invoke($request);

        $body = json_decode((string) $response->getContent(), true);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('app_delivery_quote_debug', $body['route']);
        self::assertSame('/api/v1/deliveries/quote/debug', $body['pathInfo']);
    }
}
