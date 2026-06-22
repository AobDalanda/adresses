<?php

declare(strict_types=1);

namespace App\Tests\Api\Controller;

use App\Api\Controller\AuthenticatedUserResolver;
use App\Api\Controller\NotificationMarkReadAction;
use App\Entity\UserAccount;
use App\Security\AuthenticatedIdentity;
use App\Security\AuthenticatedIdentityFactory;
use App\Security\RequestIdentityResolver;
use App\Service\JwtAuthService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;

final class NotificationMarkReadActionTest extends TestCase
{
    public function testMarkReadUpdatesAuthenticatedUserNotification(): void
    {
        $id = '01900000-0000-7000-8000-000000000001';
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())
            ->method('executeStatement')
            ->with(
                self::stringContains('UPDATE user_notification SET read_at'),
                ['id' => $id, 'userId' => 42]
            )
            ->willReturn(1);

        $response = (new NotificationMarkReadAction($db, $this->users(42)))->__invoke($id, new Request());

        self::assertSame(204, $response->getStatusCode());
    }

    public function testMarkReadReturnsNotFoundWhenNotificationDoesNotBelongToUser(): void
    {
        $id = '01900000-0000-7000-8000-000000000001';
        $db = $this->createMock(Connection::class);
        $db->method('executeStatement')->willReturn(0);

        $response = (new NotificationMarkReadAction($db, $this->users(42)))->__invoke($id, new Request());

        self::assertSame(404, $response->getStatusCode());
    }

    private function users(int $userId): AuthenticatedUserResolver
    {
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn(null);

        $jwt = $this->createMock(JwtAuthService::class);
        $jwt->method('decodeFromRequest')->willReturn(['typ' => 'mobile', 'uid' => $userId]);

        $identities = $this->createMock(AuthenticatedIdentityFactory::class);
        $identities->method('fromMobileClaims')->willReturnCallback(
            fn (array $claims): AuthenticatedIdentity => new AuthenticatedIdentity(
                $this->user($userId),
                'mobile',
                ['ROLE_USER'],
                $claims
            )
        );

        return new AuthenticatedUserResolver(new RequestIdentityResolver($security, $jwt, $identities));
    }

    private function user(int $id): UserAccount
    {
        $user = new UserAccount();
        $property = new \ReflectionProperty(UserAccount::class, 'id');
        $property->setValue($user, $id);

        return $user;
    }
}
