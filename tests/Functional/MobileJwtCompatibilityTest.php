<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class MobileJwtCompatibilityTest extends WebTestCase
{
    public function testInvalidBearerKeepsProviderProfileV1Response(): void
    {
        $client = static::createClient();
        $client->request(
            'GET',
            '/api/v1/provider/profile',
            server: ['HTTP_AUTHORIZATION' => 'Bearer invalid-token']
        );

        self::assertSame(401, $client->getResponse()->getStatusCode());
        self::assertSame('{"message":"Unauthorized"}', $client->getResponse()->getContent());
    }

    public function testInvalidBearerKeepsAdminProviderV1Response(): void
    {
        $client = static::createClient();
        $client->request(
            'GET',
            '/api/v1/admin/providers',
            server: ['HTTP_AUTHORIZATION' => 'Bearer invalid-token']
        );

        self::assertSame(403, $client->getResponse()->getStatusCode());
        self::assertSame('{"message":"Forbidden"}', $client->getResponse()->getContent());
    }
}
