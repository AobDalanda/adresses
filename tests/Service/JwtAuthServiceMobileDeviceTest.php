<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\BackOfficeAccountService;
use App\Service\JwtAuthService;
use App\Service\UserAccountService;
use App\Security\Exception\SessionInvalidatedException;
use Doctrine\DBAL\Connection;
use Lexik\Bundle\JWTAuthenticationBundle\Encoder\JWTEncoderInterface;
use PHPUnit\Framework\TestCase;

final class JwtAuthServiceMobileDeviceTest extends TestCase
{
    public function testDecodedMobileTokenRequiresActiveDeviceMatch(): void
    {
        $encoder = $this->createMock(JWTEncoderInterface::class);
        $encoder->method('decode')->with('valid-token')->willReturn([
            'uid' => 42,
            'typ' => 'mobile',
            'tv' => 5,
            'did' => 'device-1',
            'exp' => time() + 300,
        ]);

        $users = $this->createMock(UserAccountService::class);
        $users->method('findTokenVersionById')->with(42)->willReturn(5);
        $users->method('findActiveMobileDeviceIdById')->with(42)->willReturn('device-1');

        $service = new JwtAuthService(
            $encoder,
            $users,
            new BackOfficeAccountService($this->createMock(Connection::class)),
        );

        $claims = $service->decodeToken('valid-token');

        self::assertNotNull($claims);
        self::assertSame('device-1', $claims['did']);
    }

    public function testDecodedMobileTokenRejectsPreviousDevice(): void
    {
        $encoder = $this->createMock(JWTEncoderInterface::class);
        $encoder->method('decode')->with('old-device-token')->willReturn([
            'uid' => 42,
            'typ' => 'mobile',
            'tv' => 5,
            'did' => 'device-1',
            'exp' => time() + 300,
        ]);

        $users = $this->createMock(UserAccountService::class);
        $users->method('findTokenVersionById')->with(42)->willReturn(5);
        $users->method('findActiveMobileDeviceIdById')->with(42)->willReturn('device-2');

        $service = new JwtAuthService(
            $encoder,
            $users,
            new BackOfficeAccountService($this->createMock(Connection::class)),
        );

        $this->expectException(SessionInvalidatedException::class);
        $this->expectExceptionMessage('SESSION_INVALIDATED');

        $service->decodeToken('old-device-token');
    }

    public function testDecodedMobileTokenRejectsPreviousSessionVersion(): void
    {
        $encoder = $this->createMock(JWTEncoderInterface::class);
        $encoder->method('decode')->with('old-version-token')->willReturn([
            'uid' => 42,
            'typ' => 'mobile',
            'tv' => 5,
            'did' => 'device-1',
            'exp' => time() + 300,
        ]);

        $users = $this->createMock(UserAccountService::class);
        $users->method('findTokenVersionById')->with(42)->willReturn(6);

        $service = new JwtAuthService(
            $encoder,
            $users,
            new BackOfficeAccountService($this->createMock(Connection::class)),
        );

        $this->expectException(SessionInvalidatedException::class);
        $this->expectExceptionMessage('SESSION_INVALIDATED');

        $service->decodeToken('old-version-token');
    }
}
