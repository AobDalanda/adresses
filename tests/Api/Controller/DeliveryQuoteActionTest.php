<?php

declare(strict_types=1);

namespace App\Tests\Api\Controller;

use App\Api\Controller\DeliveryQuoteAction;
use App\Service\DeliveryQuoteService;
use App\Service\JwtAuthService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class DeliveryQuoteActionTest extends TestCase
{
    public function testQuoteRequiresMobileAuthentication(): void
    {
        $jwt = $this->createMock(JwtAuthService::class);
        $jwt->method('decodeFromRequest')->willReturn(null);

        $response = (new DeliveryQuoteAction($jwt, $this->createMock(DeliveryQuoteService::class)))->__invoke(
            new Request(content: '{}')
        );

        self::assertSame(401, $response->getStatusCode());
    }

    public function testQuoteReturnsServicePayload(): void
    {
        $jwt = $this->createMock(JwtAuthService::class);
        $jwt->method('decodeFromRequest')->willReturn(['typ' => 'mobile', 'uid' => 12]);

        $quotes = $this->createMock(DeliveryQuoteService::class);
        $quotes->expects(self::once())
            ->method('quote')
            ->with(
                ['addressName' => 'Domicile', 'userIdentifier' => 'USR_12'],
                'ADR_TOKEN',
                'STANDARD',
                'MOTO',
                12
            )
            ->willReturn([
                'recipient' => ['id' => 'USR_34', 'firstName' => 'Mamadou', 'lastName' => 'Diallo', 'phone' => '+224620123456'],
                'distanceKm' => 7.4,
                'durationMinutes' => 28,
                'deliveryCost' => 27000,
                'currency' => 'GNF',
            ]);

        $response = (new DeliveryQuoteAction($jwt, $quotes))->__invoke(
            new Request(content: '{"departure":{"addressName":"Domicile","userIdentifier":"USR_12"},"destination":"ADR_TOKEN"}')
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            '{"recipient":{"id":"USR_34","firstName":"Mamadou","lastName":"Diallo","phone":"+224620123456"},"distanceKm":7.4,"durationMinutes":28,"deliveryCost":27000,"currency":"GNF"}',
            $response->getContent()
        );
    }

    public function testQuoteAcceptsIntegerUserIdentifierPayload(): void
    {
        $jwt = $this->createMock(JwtAuthService::class);
        $jwt->method('decodeFromRequest')->willReturn(['typ' => 'mobile', 'uid' => 12]);

        $quotes = $this->createMock(DeliveryQuoteService::class);
        $quotes->expects(self::once())
            ->method('quote')
            ->with(
                ['addressName' => 'Domicile', 'userIdentifier' => 12],
                'ADR_TOKEN',
                'STANDARD',
                'MOTO',
                12
            )
            ->willReturn([
                'recipient' => ['id' => 'USR_34', 'firstName' => 'Mamadou', 'lastName' => 'Diallo', 'phone' => '+224620123456'],
                'distanceKm' => 7.4,
                'durationMinutes' => 28,
                'deliveryCost' => 27000,
                'currency' => 'GNF',
            ]);

        $response = (new DeliveryQuoteAction($jwt, $quotes))->__invoke(
            new Request(content: '{"departure":{"addressName":"Domicile","userIdentifier":12},"destination":"ADR_TOKEN"}')
        );

        self::assertSame(200, $response->getStatusCode());
    }
}
