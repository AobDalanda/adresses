<?php

declare(strict_types=1);

namespace App\Tests\Api\Controller;

use App\Api\Controller\AuthenticatedUserResolver;
use App\Api\Controller\PushDeviceRegisterAction;
use App\Entity\UserAccount;
use App\Security\AuthenticatedIdentity;
use App\Security\AuthenticatedIdentityFactory;
use App\Security\RequestIdentityResolver;
use App\Service\JwtAuthService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;

final class PushDeviceRegisterActionTest extends TestCase
{
    public function testRegisterStoresFcmTokenForAuthenticatedMobileUser(): void
    {
        $statements = [];
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())
            ->method('transactional')
            ->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $db->expects(self::exactly(2))
            ->method('executeStatement')
            ->willReturnCallback(static function (string $sql, array $params = []) use (&$statements): int {
                $statements[] = [$sql, $params];

                return 1;
            });

        $response = (new PushDeviceRegisterAction($db, $this->users(42)))->__invoke(
            new Request(content: '{"token":" fcm-token ","platform":"ANDROID","deviceId":"device-1"}')
        );

        self::assertSame(204, $response->getStatusCode());
        self::assertStringContainsString('UPDATE user_push_device', $statements[0][0]);
        self::assertSame([
            'userId' => 42,
            'platform' => 'android',
            'deviceId' => 'device-1',
            'tokenHash' => hash('sha256', 'fcm-token'),
        ], $statements[0][1]);
        self::assertStringContainsString('INSERT INTO user_push_device', $statements[1][0]);
        self::assertSame([
            'userId' => 42,
            'tokenHash' => hash('sha256', 'fcm-token'),
            'token' => 'fcm-token',
            'platform' => 'android',
            'deviceId' => 'device-1',
        ], $statements[1][1]);
    }

    public function testRegisterWithoutDeviceIdDoesNotDisableOtherTokens(): void
    {
        $statements = [];
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())
            ->method('transactional')
            ->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $db->expects(self::once())
            ->method('executeStatement')
            ->willReturnCallback(static function (string $sql, array $params = []) use (&$statements): int {
                $statements[] = [$sql, $params];

                return 1;
            });

        $response = (new PushDeviceRegisterAction($db, $this->users(42)))->__invoke(
            new Request(content: '{"token":"fcm-token","platform":"ios"}')
        );

        self::assertSame(204, $response->getStatusCode());
        self::assertStringContainsString('INSERT INTO user_push_device', $statements[0][0]);
        self::assertNull($statements[0][1]['deviceId']);
    }

    public function testRegisterRejectsInvalidPayload(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('transactional');
        $db->expects(self::never())->method('executeStatement');

        $response = (new PushDeviceRegisterAction($db, $this->users(42)))->__invoke(
            new Request(content: '{"token":"","platform":"desktop"}')
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('{"message":"Jeton ou plateforme invalide"}', $response->getContent());
    }

    public function testRegisterRequiresMobileAuthentication(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('transactional');
        $db->expects(self::never())->method('executeStatement');

        $response = (new PushDeviceRegisterAction($db, $this->users(null)))->__invoke(
            new Request(content: '{"token":"fcm-token","platform":"ios"}')
        );

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
