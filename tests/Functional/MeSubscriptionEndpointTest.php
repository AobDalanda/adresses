<?php

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class MeSubscriptionEndpointTest extends WebTestCase
{
    public function testMeSubscriptionRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/me/subscription');

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }
}
