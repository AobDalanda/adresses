<?php

namespace App\Service\Subscription;

use App\Entity\UsageCounter;
use App\Entity\UserAccount;
use App\Entity\UserSubscription;
use App\Repository\UsageCounterRepository;
use Doctrine\ORM\EntityManagerInterface;

class UsageCounterManager
{
    public function __construct(
        private readonly UsageCounterRepository $usageCounters,
        private readonly EntityManagerInterface $entityManager
    ) {
    }

    public function getCurrentCounter(UserAccount $user, UserSubscription $subscription): UsageCounter
    {
        $counter = $this->usageCounters->findOneForUserAndPeriod(
            $user,
            $subscription->getCurrentPeriodStart(),
            $subscription->getCurrentPeriodEnd()
        );

        if ($counter instanceof UsageCounter) {
            return $counter;
        }

        $counter = (new UsageCounter())
            ->setUser($user)
            ->setPeriodStart($subscription->getCurrentPeriodStart())
            ->setPeriodEnd($subscription->getCurrentPeriodEnd());

        $this->entityManager->persist($counter);

        return $counter;
    }

    public function incrementAddressesCreated(UserAccount $user, UserSubscription $subscription): void
    {
        $this->getCurrentCounter($user, $subscription)->incrementAddressesCreated();
        $this->entityManager->flush();
    }

    public function incrementQrCodesGenerated(UserAccount $user, UserSubscription $subscription): void
    {
        $this->getCurrentCounter($user, $subscription)->incrementQrCodesGenerated();
        $this->entityManager->flush();
    }

    public function incrementDeliveriesCreated(UserAccount $user, UserSubscription $subscription): void
    {
        $this->getCurrentCounter($user, $subscription)->incrementDeliveriesCreated();
        $this->entityManager->flush();
    }
}
