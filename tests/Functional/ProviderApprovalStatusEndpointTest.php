<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ProviderApprovalStatusEndpointTest extends WebTestCase
{
    public function testProviderApprovalStatusRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/provider/approval-status');

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }
}
