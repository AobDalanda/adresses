<?php

namespace App\Tests\Service;

use App\Entity\SubscriptionPlan;
use App\Entity\UserAccount;
use App\Entity\UserSubscription;
use App\Enum\SubscriptionPlanCode;
use App\Enum\UserSubscriptionStatus;
use App\Exception\SubscriptionLimitReachedException;
use App\Repository\SubscriptionPlanRepository;
use App\Service\Subscription\PlanLimitChecker;
use App\Service\Subscription\SubscriptionEventLogger;
use App\Service\Subscription\SubscriptionManager;
use App\Service\Subscription\UsageCounterManager;
use App\Service\UserAddressService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class PlanLimitCheckerTest extends TestCase
{
    public function testAssertCanTrackDeliveryThrowsWhenFeatureDisabled(): void
    {
        $user = new UserAccount();
        $plan = (new SubscriptionPlan())
            ->setCode(SubscriptionPlanCode::FREE)
            ->setName('Free')
            ->setCanTrackDelivery(false);

        $subscription = (new UserSubscription())
            ->setUser($user)
            ->setPlan($plan)
            ->setStatus(UserSubscriptionStatus::ACTIVE);

        $subscriptions = $this->createMock(SubscriptionManager::class);
        $subscriptions->method('getActiveSubscription')->willReturn($subscription);

        $plans = $this->createMock(SubscriptionPlanRepository::class);
        $plans->method('findAllActive')->willReturn([$plan]);

        $checker = new PlanLimitChecker(
            $subscriptions,
            $this->createMock(UsageCounterManager::class),
            $plans,
            $this->createMock(UserAddressService::class),
            $this->createMock(Connection::class),
            $this->createMock(SubscriptionEventLogger::class)
        );

        $this->expectException(SubscriptionLimitReachedException::class);
        $checker->assertCanTrackDelivery($user);
    }
}
