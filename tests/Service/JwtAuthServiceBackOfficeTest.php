<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\BackOfficeAccountService;
use App\Service\JwtAuthService;
use App\Service\UserAccountService;
use Doctrine\DBAL\Connection;
use Lexik\Bundle\JWTAuthenticationBundle\Encoder\JWTEncoderInterface;
use PHPUnit\Framework\TestCase;

final class JwtAuthServiceBackOfficeTest extends TestCase
{
    public function testDecodedBackOfficeTokenAcceptsNormalizedAudienceArray(): void
    {
        $encoder = $this->createMock(JWTEncoderInterface::class);
        $encoder->method('decode')->with('valid-token')->willReturn([
            'uid' => 1,
            'typ' => 'back_office',
            'aud' => ['bo.aldahim.com'],
            'tv' => 4,
            'exp' => time() + 300,
        ]);

        $db = $this->createMock(Connection::class);
        $db->method('fetchOne')->willReturn(4);

        $service = new JwtAuthService(
            $encoder,
            $this->createMock(UserAccountService::class),
            new BackOfficeAccountService($db),
        );

        $claims = $service->decodeToken('valid-token');

        self::assertNotNull($claims);
        self::assertSame('back_office', $claims['typ']);
    }
}
