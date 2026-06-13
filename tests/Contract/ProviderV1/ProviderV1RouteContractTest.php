<?php

declare(strict_types=1);

namespace App\Tests\Contract\ProviderV1;

use App\Api\Controller\AdminProviderDetailAction;
use App\Api\Controller\AdminProviderListAction;
use App\Api\Controller\AdminProviderStatusAction;
use App\Api\Controller\DriverRegistrationAction;
use App\Api\Controller\ProviderProfileGetAction;
use App\Api\Controller\ProviderProfileUpdateAction;
use App\Api\Controller\UploadFileAction;
use App\Controller\ProviderUploadSessionController;
use App\Controller\ProviderApplicationV2Controller;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouterInterface;

final class ProviderV1RouteContractTest extends KernelTestCase
{
    #[DataProvider('providerRoutes')]
    public function testProviderV1RouteResolvesToExpectedController(
        string $method,
        string $path,
        string $routeName,
        string $controller
    ): void {
        self::bootKernel();

        $router = self::getContainer()->get(RouterInterface::class);
        self::assertInstanceOf(RouterInterface::class, $router);

        $request = Request::create($path, $method);
        $context = (new RequestContext())->fromRequest($request);
        $matcher = new UrlMatcher($router->getRouteCollection(), $context);
        $parameters = $matcher->match($request->getPathInfo());

        self::assertSame($routeName, $parameters['_route'] ?? null);
        self::assertSame($controller, $parameters['_controller'] ?? null);
    }

    /**
     * @return iterable<string, array{string, string, string, class-string}>
     */
    public static function providerRoutes(): iterable
    {
        yield 'driver registration' => [
            'POST',
            '/api/v1/user/register/driver',
            'app_user_register_driver',
            DriverRegistrationAction::class,
        ];
        yield 'upload' => [
            'POST',
            '/api/v1/uploads',
            'app_upload_file',
            UploadFileAction::class,
        ];
        yield 'provider profile get' => [
            'GET',
            '/api/v1/provider/profile',
            'app_provider_profile_get',
            ProviderProfileGetAction::class,
        ];
        yield 'provider profile update' => [
            'PATCH',
            '/api/v1/provider/profile',
            'app_provider_profile_update',
            ProviderProfileUpdateAction::class,
        ];
        yield 'provider admin list' => [
            'GET',
            '/api/v1/admin/providers',
            'app_admin_provider_list',
            AdminProviderListAction::class,
        ];
        yield 'provider admin detail' => [
            'GET',
            '/api/v1/admin/providers/12',
            'app_admin_provider_detail',
            AdminProviderDetailAction::class,
        ];
        yield 'provider admin status' => [
            'PATCH',
            '/api/v1/admin/providers/12/status',
            'app_admin_provider_status',
            AdminProviderStatusAction::class,
        ];
        yield 'v2 secure upload session' => [
            'POST',
            '/api/v2/provider-upload-sessions',
            'app_v2_provider_upload_session_create',
            ProviderUploadSessionController::class.'::create',
        ];
        yield 'v2 secure asset upload' => [
            'POST',
            '/api/v2/provider-upload-sessions/01975aa9-df9c-7b25-b797-6b1ca912e68e/assets',
            'app_v2_provider_upload_asset_create',
            ProviderUploadSessionController::class.'::upload',
        ];
        yield 'v2 provider application create' => [
            'POST',
            '/api/v2/provider/applications',
            'app_v2_provider_application_create',
            ProviderApplicationV2Controller::class.'::create',
        ];
        yield 'v2 provider application submit' => [
            'POST',
            '/api/v2/provider/applications/01975aa9-df9c-7b25-b797-6b1ca912e68e/submit',
            'app_v2_provider_application_submit',
            ProviderApplicationV2Controller::class.'::submit',
        ];
    }
}
