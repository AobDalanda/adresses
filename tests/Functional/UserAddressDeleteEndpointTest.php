<?php

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class UserAddressDeleteEndpointTest extends WebTestCase
{
    public function testDeleteAddressRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request('DELETE', '/api/v1/user/address/1');

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }
}
