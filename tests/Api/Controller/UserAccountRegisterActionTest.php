<?php

namespace App\Tests\Api\Controller;

use App\Api\Controller\UserAccountRegisterAction;
use App\Service\AccountDocumentStorage;
use App\Service\OtpService;
use App\Service\ProfilePhotoStorage;
use App\Service\UserAccountService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class UserAccountRegisterActionTest extends TestCase
{
    public function testRegisterPersistsPendingEmail(): void
    {
        $userAccountService = $this->createMock(UserAccountService::class);
        $userAccountService->method('userExists')->with('620000000')->willReturn(false);
        $userAccountService->method('findPendingRegistration')->with('620000000')->willReturn(null);
        $userAccountService
            ->expects(self::once())
            ->method('createPendingRegistration')
            ->with(
                '620000000',
                'Jane Doe',
                null,
                'client',
                null,
                null,
                'jane@example.com',
                'CNI 123456'
            );

        $otpService = $this->createMock(OtpService::class);
        $otpService->expects(self::once())->method('requestOtp')->with('620000000');

        $controller = new UserAccountRegisterAction(
            $userAccountService,
            $otpService,
            $this->createMock(ProfilePhotoStorage::class),
            $this->createMock(AccountDocumentStorage::class)
        );

        $response = $controller->__invoke(new Request(content: json_encode([
            'phone' => '620000000',
            'fullName' => 'Jane Doe',
            'email' => 'Jane@Example.com',
            'identityDocumentNumber' => 'CNI 123456',
            'accountType' => 'client',
        ], JSON_THROW_ON_ERROR)));

        self::assertSame(202, $response->getStatusCode());
        self::assertSame(
            ['message' => 'OTP envoyé'],
            json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR)
        );
    }
}
