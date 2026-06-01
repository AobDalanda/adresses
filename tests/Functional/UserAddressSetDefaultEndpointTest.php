<?php

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class UserAddressSetDefaultEndpointTest extends WebTestCase
{
    public function testSetDefaultAddressRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/v1/user/address/default',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['addressId' => 1], JSON_THROW_ON_ERROR)
        );

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }
}
