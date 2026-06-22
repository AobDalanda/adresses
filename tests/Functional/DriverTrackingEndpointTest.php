<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DriverTrackingEndpointTest extends WebTestCase
{
    public function testLocationUpdateRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/v1/drivers/location',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"driverId":15,"latitude":9.6412,"longitude":-13.5784,"accuracy":5.3}'
        );

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testLastLocationRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/drivers/15/location');

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testHistoryRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/drivers/15/locations');

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testMercureAuthorizationRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/v1/drivers/15/mercure-authorization');

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testDeliveryNotificationsMercureAuthorizationRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/v1/deliveries/mercure-authorization');

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }
}
