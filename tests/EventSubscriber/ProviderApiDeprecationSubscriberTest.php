<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\ProviderApiDeprecationSubscriber;
use App\Service\ProviderApiRolloutPolicy;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;

final class ProviderApiDeprecationSubscriberTest extends TestCase
{
    public function testV1ProviderResponseIsDeprecatedAndMeasured(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())
            ->method('executeStatement')
            ->with(
                self::stringContains('provider_api_usage_daily'),
                self::callback(static fn (array $parameters): bool =>
                    $parameters['version'] === 'v1'
                    && $parameters['clientVersion'] === '2.4.1'
                    && $parameters['responseClass'] === 4
                ),
            )
            ->willReturn(1);
        $subscriber = new ProviderApiDeprecationSubscriber(
            $db,
            new ProviderApiRolloutPolicy(true, 10, 'salt', '2027-06-30 UTC'),
            new NullLogger(),
        );
        $request = Request::create('/api/v1/provider/profile');
        $request->headers->set('X-App-Version', '2.4.1');
        $response = new Response('', 401);

        $subscriber->onResponse($this->event($request, $response));

        self::assertSame('true', $response->headers->get('Deprecation'));
        self::assertSame('Wed, 30 Jun 2027 00:00:00 GMT', $response->headers->get('Sunset'));
        self::assertStringContainsString('successor-version', (string) $response->headers->get('Link'));
    }

    public function testUnrelatedV1RouteIsNotDeprecated(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('executeStatement');
        $subscriber = new ProviderApiDeprecationSubscriber(
            $db,
            new ProviderApiRolloutPolicy(false, 0, 'salt', null),
            new NullLogger(),
        );
        $response = new Response();

        $subscriber->onResponse($this->event(Request::create('/api/v1/subscription'), $response));

        self::assertFalse($response->headers->has('Deprecation'));
    }

    private function event(Request $request, Response $response): ResponseEvent
    {
        $kernel = $this->createMock(KernelInterface::class);

        return new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);
    }
}
