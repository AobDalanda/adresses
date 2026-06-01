<?php

namespace App\Tests\Service;

use App\Entity\SubscriptionPlan;
use App\Entity\UserAccount;
use App\Enum\SubscriptionPlanCode;
use App\Repository\SubscriptionPlanRepository;
use App\Repository\UserAccountRepository;
use App\Repository\UserSubscriptionRepository;
use App\Service\Subscription\NotificationManager;
use App\Service\Subscription\SubscriptionEventLogger;
use App\Service\Subscription\SubscriptionManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class SubscriptionManagerTest extends TestCase
{
    public function testGetPlanByCodeThrowsForUnknownCode(): void
    {
        $plans = $this->createMock(SubscriptionPlanRepository::class);
        $plans->expects(self::never())->method('findOneBy');

        $manager = new SubscriptionManager(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(UserAccountRepository::class),
            $plans,
            $this->createMock(UserSubscriptionRepository::class),
            $this->createMock(SubscriptionEventLogger::class),
            new NotificationManager()
        );

        $this->expectException(\App\Exception\InvalidSubscriptionPlanException::class);
        $manager->getPlanByCode('unknown');
    }

    public function testGetPlanByCodeReturnsActivePlan(): void
    {
        $plan = (new SubscriptionPlan())
            ->setCode(SubscriptionPlanCode::BASIC)
            ->setName('Basic');

        $plans = $this->createMock(SubscriptionPlanRepository::class);
        $plans->method('findOneBy')->willReturn($plan);

        $manager = new SubscriptionManager(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(UserAccountRepository::class),
            $plans,
            $this->createMock(UserSubscriptionRepository::class),
            $this->createMock(SubscriptionEventLogger::class),
            new NotificationManager()
        );

        self::assertSame($plan, $manager->getPlanByCode('BASIC'));
    }
}
