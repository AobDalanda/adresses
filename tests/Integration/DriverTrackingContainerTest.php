<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repository\DriverLocationRepositoryInterface;
use App\Service\Tracking\DriverTrackingService;
use App\Service\Tracking\LocationPublisherInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mercure\Authorization;

final class DriverTrackingContainerTest extends KernelTestCase
{
    public function testTrackingServicesAreWired(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        self::assertInstanceOf(DriverTrackingService::class, $container->get(DriverTrackingService::class));
        self::assertInstanceOf(
            DriverLocationRepositoryInterface::class,
            $container->get(DriverLocationRepositoryInterface::class)
        );
        self::assertInstanceOf(
            LocationPublisherInterface::class,
            $container->get(LocationPublisherInterface::class)
        );
    }

    public function testMercureAuthorizationCookieIsHttpOnlyAndScopedToHub(): void
    {
        self::bootKernel();
        $authorization = static::getContainer()->get(Authorization::class);

        $cookie = $authorization->createCookie(
            Request::create('http://localhost/api/v1/drivers/15/mercure-authorization'),
            ['driver/15/location']
        );

        self::assertSame('mercureAuthorization', $cookie->getName());
        self::assertSame('/.well-known/mercure', $cookie->getPath());
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame('strict', $cookie->getSameSite());
    }
}
