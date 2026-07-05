<?php

declare(strict_types=1);

namespace App\Tests\Contract;

use App\Api\Controller\MissionDetailAction;
use App\Api\Controller\MissionListAction;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouterInterface;

final class MissionV1RouteContractTest extends KernelTestCase
{
    #[DataProvider('routes')]
    public function testRouteResolves(string $path, string $routeName, string $controller): void
    {
        self::bootKernel();
        $router = self::getContainer()->get(RouterInterface::class);
        $request = Request::create($path, 'GET');
        $parameters = (new UrlMatcher(
            $router->getRouteCollection(),
            (new RequestContext())->fromRequest($request)
        ))->match($request->getPathInfo());

        self::assertSame($routeName, $parameters['_route'] ?? null);
        self::assertSame($controller, $parameters['_controller'] ?? null);
    }

    /** @return iterable<string, array{string, string, class-string}> */
    public static function routes(): iterable
    {
        yield 'list' => ['/api/v1/missions', 'app_mission_list', MissionListAction::class];
        yield 'detail' => [
            '/api/v1/missions/01975aa9-df9c-7b25-b797-6b1ca912e68f',
            'app_mission_detail',
            MissionDetailAction::class,
        ];
    }
}
