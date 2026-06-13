<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\UserAccount;
use App\Security\AuthenticatedIdentity;
use App\Security\ProviderAdministrationVoter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

final class ProviderAdministrationVoterTest extends TestCase
{
    /**
     * @param list<string> $roles
     */
    #[DataProvider('permissions')]
    public function testPermissionMatrix(array $roles, string $attribute, bool $expected): void
    {
        $identity = new AuthenticatedIdentity(
            $this->user(),
            'mobile',
            $roles,
            ['uid' => 42, 'typ' => 'mobile']
        );
        $token = new PostAuthenticationToken($identity, 'main', $roles);

        $result = (new ProviderAdministrationVoter())->vote($token, null, [$attribute]);

        self::assertSame(
            $expected ? VoterInterface::ACCESS_GRANTED : VoterInterface::ACCESS_DENIED,
            $result
        );
    }

    /**
     * @return iterable<string, array{list<string>, string, bool}>
     */
    public static function permissions(): iterable
    {
        yield 'reviewer lists' => [
            ['ROLE_USER', 'ROLE_PROVIDER_REVIEWER'],
            ProviderAdministrationVoter::LIST,
            true,
        ];
        yield 'reviewer cannot decide' => [
            ['ROLE_USER', 'ROLE_PROVIDER_REVIEWER'],
            ProviderAdministrationVoter::DECIDE,
            false,
        ];
        yield 'approver decides' => [
            ['ROLE_USER', 'ROLE_PROVIDER_APPROVER'],
            ProviderAdministrationVoter::DECIDE,
            true,
        ];
        yield 'approver cannot suspend' => [
            ['ROLE_USER', 'ROLE_PROVIDER_APPROVER'],
            ProviderAdministrationVoter::SUSPEND,
            false,
        ];
        yield 'security admin views' => [
            ['ROLE_USER', 'ROLE_PROVIDER_SECURITY_ADMIN'],
            ProviderAdministrationVoter::VIEW,
            true,
        ];
        yield 'security admin suspends' => [
            ['ROLE_USER', 'ROLE_PROVIDER_SECURITY_ADMIN'],
            ProviderAdministrationVoter::SUSPEND,
            true,
        ];
        yield 'security admin cannot decide' => [
            ['ROLE_USER', 'ROLE_PROVIDER_SECURITY_ADMIN'],
            ProviderAdministrationVoter::DECIDE,
            false,
        ];
        yield 'admin can decide' => [
            ['ROLE_USER', 'ROLE_ADMIN'],
            ProviderAdministrationVoter::DECIDE,
            true,
        ];
        yield 'admin can suspend' => [
            ['ROLE_USER', 'ROLE_ADMIN'],
            ProviderAdministrationVoter::SUSPEND,
            true,
        ];
    }

    private function user(): UserAccount
    {
        $user = (new UserAccount())
            ->setPhone('620000000')
            ->setAccountType('admin');
        $property = new \ReflectionProperty(UserAccount::class, 'id');
        $property->setValue($user, 42);

        return $user;
    }
}
