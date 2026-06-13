<?php

namespace App\Tests\Controller;

use App\Controller\AuthController;
use App\Service\JwtAuthService;
use App\Service\OtpService;
use App\Service\UserAccountAssetUrlResolver;
use App\Service\UserAccountService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;

final class AuthControllerTest extends TestCase
{
    public function testRequestOtpReturns404WhenVerifiedUserDoesNotExist(): void
    {
        $otpService = $this->createMock(OtpService::class);
        $otpService->expects(self::never())->method('requestOtp');

        $userAccountService = $this->createMock(UserAccountService::class);
        $userAccountService
            ->expects(self::once())
            ->method('verifiedUserExists')
            ->with('620000000')
            ->willReturn(false);

        $controller = new AuthController(
            $otpService,
            $userAccountService,
            $this->createMock(JwtAuthService::class),
            $this->createMock(UserAccountAssetUrlResolver::class)
        );
        $controller->setContainer(new Container());

        $response = $controller->requestOtp(new Request(content: json_encode([
            'phone' => '620000000',
        ], JSON_THROW_ON_ERROR)));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('{"message":"USER_NOT_FOUND"}', $response->getContent());
    }
}
