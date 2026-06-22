<?php

declare(strict_types=1);

namespace App\Tests\Api\Controller;

use App\Api\Controller\AuthenticatedUserResolver;
use App\Api\Controller\NotificationListAction;
use App\Entity\UserAccount;
use App\Security\AuthenticatedIdentity;
use App\Security\AuthenticatedIdentityFactory;
use App\Security\RequestIdentityResolver;
use App\Service\JwtAuthService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;

final class NotificationListActionTest extends TestCase
{
    public function testListReturnsAuthenticatedUserNotifications(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())
            ->method('fetchAllAssociative')
            ->with(self::stringContains('FROM user_notification'), ['userId' => 42])
            ->willReturn([[
                'id' => '01900000-0000-7000-8000-000000000001',
                'type' => 'provider.application.submitted',
                'title' => 'Dossier recu',
                'body' => 'Votre dossier Prestataire a bien ete soumis.',
                'data' => '{"applicationId":"app-1"}',
                'read_at' => null,
                'created_at' => '2026-06-22 10:00:00+00',
            ]]);

        $response = (new NotificationListAction($db, $this->users(42)))->__invoke(new Request());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            '{"notifications":[{"id":"01900000-0000-7000-8000-000000000001","type":"provider.application.submitted","title":"Dossier recu","body":"Votre dossier Prestataire a bien ete soumis.","data":{"applicationId":"app-1"},"readAt":null,"createdAt":"2026-06-22 10:00:00+00"}]}',
            $response->getContent()
        );
    }

    public function testListRequiresMobileAuthentication(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('fetchAllAssociative');

        $response = (new NotificationListAction($db, $this->users(null)))->__invoke(new Request());

        self::assertSame(401, $response->getStatusCode());
    }

    private function users(?int $userId): AuthenticatedUserResolver
    {
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn(null);

        $jwt = $this->createMock(JwtAuthService::class);
        $jwt->method('decodeFromRequest')->willReturn($userId === null ? null : ['typ' => 'mobile', 'uid' => $userId]);

        $identities = $this->createMock(AuthenticatedIdentityFactory::class);
        $identities->method('fromMobileClaims')->willReturnCallback(
            fn (array $claims): ?AuthenticatedIdentity => $userId === null
                ? null
                : new AuthenticatedIdentity($this->user($userId), 'mobile', ['ROLE_USER'], $claims)
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
