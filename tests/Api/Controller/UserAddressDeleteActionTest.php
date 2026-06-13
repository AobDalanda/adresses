<?php

namespace App\Tests\Api\Controller;

use App\Api\Controller\UserAddressDeleteAction;
use App\Entity\SubscriptionPlan;
use App\Entity\UserAccount;
use App\Entity\UserSubscription;
use App\Enum\SubscriptionPlanCode;
use App\Enum\UserSubscriptionStatus;
use App\Service\JwtAuthService;
use App\Service\Subscription\SubscriptionManager;
use App\Service\UserAddressService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;

final class UserAddressDeleteActionTest extends TestCase
{
    public function testDeleteRejectsBasicPlan(): void
    {
        $jwt = $this->createMock(JwtAuthService::class);
        $jwt->method('decodeFromRequest')->willReturn([
            'typ' => 'mobile',
            'uid' => 7,
        ]);

        $user = new UserAccount();
        $plan = (new SubscriptionPlan())
            ->setCode(SubscriptionPlanCode::BASIC)
            ->setName('Basic');
        $subscription = (new UserSubscription())
            ->setUser($user)
            ->setPlan($plan)
            ->setStatus(UserSubscriptionStatus::ACTIVE);

        $subscriptions = $this->createMock(SubscriptionManager::class);
        $subscriptions->method('getUser')->with(7)->willReturn($user);
        $subscriptions->method('getActiveSubscription')->with($user)->willReturn($subscription);

        $userAddresses = $this->createMock(UserAddressService::class);
        $userAddresses->expects(self::never())->method('isDefaultAddress');
        $userAddresses->expects(self::never())->method('deleteUserAddress');

        $controller = new UserAddressDeleteAction($jwt, $subscriptions, $userAddresses, new NullLogger());

        $response = $controller->__invoke(new Request(), 12);

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('SUBSCRIPTION_PLAN_REQUIRED', (string) $response->getContent());
    }

    public function testDeleteRejectsDefaultAddress(): void
    {
        $jwt = $this->createMock(JwtAuthService::class);
        $jwt->method('decodeFromRequest')->willReturn([
            'typ' => 'mobile',
            'uid' => 7,
        ]);

        $user = new UserAccount();
        $plan = (new SubscriptionPlan())
            ->setCode(SubscriptionPlanCode::PREMIUM)
            ->setName('Premium');
        $subscription = (new UserSubscription())
            ->setUser($user)
            ->setPlan($plan)
            ->setStatus(UserSubscriptionStatus::ACTIVE);

        $subscriptions = $this->createMock(SubscriptionManager::class);
        $subscriptions->method('getUser')->with(7)->willReturn($user);
        $subscriptions->method('getActiveSubscription')->with($user)->willReturn($subscription);

        $userAddresses = $this->createMock(UserAddressService::class);
        $userAddresses->expects(self::once())->method('isDefaultAddress')->with(7, 12)->willReturn(true);
        $userAddresses->expects(self::never())->method('deleteUserAddress');

        $controller = new UserAddressDeleteAction($jwt, $subscriptions, $userAddresses, new NullLogger());

        $response = $controller->__invoke(new Request(), 12);

        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('DEFAULT_ADDRESS_DELETE_FORBIDDEN', (string) $response->getContent());
    }
}
