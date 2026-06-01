<?php

namespace App\Tests\Service;

use App\Entity\SubscriptionPlan;
use App\Entity\UserAccount;
use App\Entity\UserSubscription;
use App\Enum\SubscriptionPlanCode;
use App\Enum\UserSubscriptionStatus;
use App\Repository\SubscriptionPlanRepository;
use App\Service\Subscription\MeSubscriptionViewFactory;
use App\Service\Subscription\PlanLimitChecker;
use App\Service\Subscription\SubscriptionManager;
use PHPUnit\Framework\TestCase;

final class MeSubscriptionViewFactoryTest extends TestCase
{
    public function testCreateIncludesServerPlansForCheckoutConsumers(): void
    {
        $user = (new UserAccount())->setPhone('+224620000000');
        $basicPlan = (new SubscriptionPlan())
            ->setCode(SubscriptionPlanCode::BASIC)
            ->setName('Basic')
            ->setDescription('Plan standard mensuel.')
            ->setPriceAmount(50000)
            ->setCurrency('GNF')
            ->setDurationDays(30)
            ->setMaxAddresses(5)
            ->setMaxQrCodes(5)
            ->setMaxDeliveriesPerMonth(20)
            ->setCanTrackDelivery(true);
        $premiumPlan = (new SubscriptionPlan())
            ->setCode(SubscriptionPlanCode::PREMIUM)
            ->setName('Premium')
            ->setDescription('Plan premium mensuel.')
            ->setPriceAmount(100000)
            ->setCurrency('GNF')
            ->setDurationDays(30)
            ->setMaxAddresses(20)
            ->setMaxQrCodes(20)
            ->setMaxDeliveriesPerMonth(100)
            ->setCanTrackDelivery(true)
            ->setCanUseCustomQrCode(true);

        $subscription = (new UserSubscription())
            ->setUser($user)
            ->setPlan($basicPlan)
            ->setStatus(UserSubscriptionStatus::ACTIVE);

        $subscriptions = $this->createMock(SubscriptionManager::class);
        $subscriptions->method('getActiveSubscription')->with($user)->willReturn($subscription);

        $planLimitChecker = $this->createMock(PlanLimitChecker::class);
        $planLimitChecker->method('buildUsageSummary')->with($user)->willReturn([
            'addresses' => ['used' => 1, 'limit' => 5],
            'qrCodes' => ['used' => 1, 'limit' => 5],
            'deliveriesThisMonth' => ['used' => 0, 'limit' => 20],
        ]);

        $plans = $this->createMock(SubscriptionPlanRepository::class);
        $plans->method('findAllActive')->willReturn([$basicPlan, $premiumPlan]);

        $factory = new MeSubscriptionViewFactory($subscriptions, $planLimitChecker, $plans);

        $payload = $factory->create($user);

        self::assertArrayHasKey('availablePlans', $payload);
        self::assertArrayHasKey('serverPlans', $payload);
        self::assertCount(2, $payload['availablePlans']);
        self::assertSame($payload['availablePlans'], $payload['serverPlans']);
        self::assertSame('BASIC', $payload['serverPlans'][0]['code']);
        self::assertSame(50000, $payload['serverPlans'][0]['priceAmount']);
        self::assertSame('PREMIUM', $payload['serverPlans'][1]['code']);
    }
}
