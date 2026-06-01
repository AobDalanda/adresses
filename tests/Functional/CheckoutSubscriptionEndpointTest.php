<?php

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CheckoutSubscriptionEndpointTest extends WebTestCase
{
    public function testCheckoutRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/v1/me/subscription/checkout', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'planCode' => 'BASIC',
            'paymentProvider' => 'orange_money',
        ], JSON_THROW_ON_ERROR));

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }
}
