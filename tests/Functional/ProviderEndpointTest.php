<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ProviderEndpointTest extends WebTestCase
{
    public function testProviderProfileRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/provider/profile');

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testProviderActivitiesRequireAuthentication(): void
    {
        $client = static::createClient();
        $client->request(
            'PATCH',
            '/api/v1/provider/profile',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"canDeliver":true,"canTransportPeople":false}'
        );

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testProviderAdministrationRequiresAdminRole(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/admin/providers');

        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testRefreshTokenIsRequired(): void
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/v1/auth/refresh-token',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{}'
        );

        self::assertSame(400, $client->getResponse()->getStatusCode());
    }
}
