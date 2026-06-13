<?php

declare(strict_types=1);

namespace App\Tests\Api\Controller;

use App\Api\Controller\AdminProviderStatusAction;
use App\Security\ProviderAdministrationVoter;
use App\Service\ProviderProfileService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;

final class AdminProviderStatusActionTest extends TestCase
{
    public function testApproverCannotSuspendProvider(): void
    {
        $security = $this->createMock(Security::class);
        $security->expects(self::exactly(2))
            ->method('isGranted')
            ->willReturnCallback(static fn (string $attribute): bool => match ($attribute) {
                ProviderAdministrationVoter::DECIDE => true,
                ProviderAdministrationVoter::SUSPEND => false,
                default => false,
            });

        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('executeStatement');

        $response = (new AdminProviderStatusAction(
            $security,
            new ProviderProfileService($db)
        ))->__invoke(
            12,
            new Request(content: '{"validationStatus":"suspended"}')
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('{"message":"Forbidden"}', $response->getContent());
    }

    public function testSecurityAdminCannotApproveProvider(): void
    {
        $security = $this->createMock(Security::class);
        $security->expects(self::exactly(3))
            ->method('isGranted')
            ->willReturnCallback(static fn (string $attribute): bool => match ($attribute) {
                ProviderAdministrationVoter::DECIDE => false,
                ProviderAdministrationVoter::SUSPEND => true,
                default => false,
            });

        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('executeStatement');

        $response = (new AdminProviderStatusAction(
            $security,
            new ProviderProfileService($db)
        ))->__invoke(
            12,
            new Request(content: '{"validationStatus":"approved"}')
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('{"message":"Forbidden"}', $response->getContent());
    }
}
