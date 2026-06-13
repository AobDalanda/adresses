<?php

namespace App\Tests\Api\Controller;

use App\Api\Controller\UserAccountRegisterVerifyAction;
use App\Service\JwtAuthService;
use App\Service\OtpService;
use App\Service\Subscription\SubscriptionManager;
use App\Service\UserAccountAssetUrlResolver;
use App\Service\UserAccountService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class UserAccountRegisterVerifyActionTest extends TestCase
{
    public function testVerifyTransfersPendingEmailToUserAccount(): void
    {
        $otpService = $this->createMock(OtpService::class);
        $otpService->method('verifyOtp')->with('620000000', '123456')->willReturn(true);

        $userAccountService = $this->createMock(UserAccountService::class);
        $userAccountService
            ->method('findPendingRegistration')
            ->with('620000000')
            ->willReturn([
                'id' => 10,
                'phone' => '620000000',
                'fullName' => 'Jane Doe',
                'email' => 'jane@example.com',
                'accountType' => 'client',
                'profilePhotoPath' => null,
                'identityDocumentPath' => null,
                'identityDocumentNumber' => 'CNI 123456',
                'driverLicensePath' => null,
            ]);
        $userAccountService
            ->expects(self::once())
            ->method('upsertUserAccount')
            ->with(
                '620000000',
                'Jane Doe',
                true,
                null,
                'client',
                null,
                null,
                'jane@example.com',
                'CNI 123456'
            )
            ->willReturn([
                'id' => 42,
                'phone' => '620000000',
                'name' => 'Jane Doe',
                'email' => 'jane@example.com',
                'verified' => true,
                'accountType' => 'client',
                'profilePhotoPath' => null,
                'identityDocumentPath' => null,
                'identityDocumentNumber' => 'CNI 123456',
                'driverLicensePath' => null,
                'createdAt' => '2026-05-17 10:30:00',
            ]);
        $userAccountService->expects(self::once())->method('markPendingRegistrationVerified')->with(10);

        $jwt = $this->createMock(JwtAuthService::class);
        $jwt->method('issueToken')->willReturn('jwt-token');

        $assetUrlResolver = $this->createMock(UserAccountAssetUrlResolver::class);
        $assetUrlResolver->method('enrich')->willReturnArgument(0);

        $subscriptions = $this->createMock(SubscriptionManager::class);
        $subscriptions->expects(self::once())->method('initializeFreeSubscription')->with(42);

        $controller = new UserAccountRegisterVerifyAction(
            $otpService,
            $userAccountService,
            $jwt,
            $assetUrlResolver,
            $subscriptions
        );

        $response = $controller->__invoke(new Request(content: json_encode([
            'phone' => '620000000',
            'otp' => '123456',
        ], JSON_THROW_ON_ERROR)));

        self::assertSame(201, $response->getStatusCode());
        self::assertStringContainsString('"email":"jane@example.com"', (string) $response->getContent());
        self::assertStringContainsString('"identityDocumentNumber":"CNI 123456"', (string) $response->getContent());
    }
}
