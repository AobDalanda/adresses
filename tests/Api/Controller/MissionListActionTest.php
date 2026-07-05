<?php

declare(strict_types=1);

namespace App\Tests\Api\Controller;

use App\Api\Controller\MissionListAction;
use App\Entity\UserAccount;
use App\Security\AuthenticatedIdentity;
use App\Security\AuthenticatedIdentityFactory;
use App\Security\RequestIdentityResolver;
use App\Security\TrackingIdentityResolver;
use App\Service\JwtAuthService;
use App\Service\MissionOverviewService;
use App\Service\ProviderProfileService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;

final class MissionListActionTest extends TestCase
{
    public function testAuthenticationIsRequired(): void
    {
        $response = $this->action(null, null)->__invoke(new Request());

        self::assertSame(401, $response->getStatusCode());
    }

    public function testApprovedDeliveryCapabilityIsRequired(): void
    {
        $response = $this->action($this->identity(42), $this->providerProfile(false, true))
            ->__invoke(new Request());

        self::assertSame(403, $response->getStatusCode());
    }

    public function testUnsupportedMissionTypeAndInvalidStatusAreRejected(): void
    {
        $action = $this->action($this->identity(42), $this->providerProfile(true, true));

        $transport = $action->__invoke(new Request(query: ['mission_type' => 'transport']));
        $invalidStatus = $action->__invoke(new Request(query: ['status' => 'pending']));

        self::assertSame(400, $transport->getStatusCode());
        self::assertStringContainsString('livraison', (string) $transport->getContent());
        self::assertSame(400, $invalidStatus->getStatusCode());
    }

    /** @param array<string, mixed>|null $profile */
    private function action(?AuthenticatedIdentity $identity, ?array $profile): MissionListAction
    {
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($identity);

        $jwt = $this->createMock(JwtAuthService::class);
        $jwt->method('decodeFromRequest')->willReturn(null);

        $identityFactory = $this->createMock(AuthenticatedIdentityFactory::class);
        $requestIdentities = new RequestIdentityResolver($security, $jwt, $identityFactory);

        $profileDb = $this->createMock(Connection::class);
        if ($identity !== null) {
            $profileDb->method('fetchAssociative')->willReturn($profile ?? false);
        }

        return new MissionListAction(
            new TrackingIdentityResolver($requestIdentities, new ProviderProfileService($profileDb)),
            new MissionOverviewService($this->createMock(Connection::class)),
        );
    }

    private function identity(int $id): AuthenticatedIdentity
    {
        $user = new UserAccount();
        (new \ReflectionProperty(UserAccount::class, 'id'))->setValue($user, $id);
        $user->setAccountType('provider');

        return new AuthenticatedIdentity($user, 'mobile', ['ROLE_USER', 'ROLE_PROVIDER'], [
            'typ' => 'mobile',
            'uid' => $id,
        ]);
    }

    /** @return array<string, mixed> */
    private function providerProfile(bool $canDeliver, bool $approved): array
    {
        return [
            'id' => 10,
            'user_id' => 42,
            'can_deliver' => $canDeliver,
            'can_transport_people' => false,
            'validation_status' => $approved ? 'approved' : 'pending',
            'created_at' => '2026-07-01T00:00:00+00:00',
            'updated_at' => '2026-07-01T00:00:00+00:00',
            'phone' => '+224620000000',
            'name' => 'Driver',
            'email' => null,
            'verified' => true,
            'account_type' => 'provider',
        ];
    }
}
