<?php

namespace App\Service\Subscription;

use App\Entity\SubscriptionPlan;
use App\Entity\UserAccount;
use App\Entity\UserSubscription;
use App\Enum\PaymentProvider;
use App\Enum\SubscriptionEventType;
use App\Enum\SubscriptionPlanCode;
use App\Enum\UserSubscriptionStatus;
use App\Exception\InvalidSubscriptionPlanException;
use App\Exception\NoActiveSubscriptionException;
use App\Repository\SubscriptionPlanRepository;
use App\Repository\UserAccountRepository;
use App\Repository\UserSubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;

class SubscriptionManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserAccountRepository $users,
        private readonly SubscriptionPlanRepository $plans,
        private readonly UserSubscriptionRepository $subscriptions,
        private readonly SubscriptionEventLogger $eventLogger,
        private readonly NotificationManager $notifications
    ) {
    }

    public function initializeFreeSubscription(int $userId): UserSubscription
    {
        $user = $this->getUser($userId);
        $current = $this->subscriptions->findCurrentForUser($user);
        if ($current instanceof UserSubscription) {
            return $current;
        }

        $plan = $this->getPlanByCode(SubscriptionPlanCode::FREE->value);
        $subscription = $this->buildSubscription($user, $plan, UserSubscriptionStatus::ACTIVE, null);

        $this->entityManager->wrapInTransaction(function () use ($user, $subscription): void {
            $this->entityManager->persist($subscription);
            $this->eventLogger->log(
                $user,
                $subscription,
                SubscriptionEventType::SUBSCRIPTION_CREATED,
                null,
                $subscription->getStatus()->value,
                ['planCode' => $subscription->getPlan()->getCode()->value]
            );
        });
        $this->entityManager->flush();

        return $subscription;
    }

    public function getActiveSubscription(UserAccount $user): UserSubscription
    {
        $subscription = $this->subscriptions->findCurrentForUser($user);
        if (!$subscription instanceof UserSubscription) {
            throw new NoActiveSubscriptionException('Aucun abonnement actif trouvé.');
        }

        return $subscription;
    }

    public function createPendingSubscription(UserAccount $user, SubscriptionPlan $plan, PaymentProvider $provider): UserSubscription
    {
        $subscription = $this->buildSubscription($user, $plan, UserSubscriptionStatus::PENDING_PAYMENT, $provider);

        $this->entityManager->persist($subscription);
        $this->eventLogger->log(
            $user,
            $subscription,
            SubscriptionEventType::SUBSCRIPTION_CREATED,
            null,
            $subscription->getStatus()->value,
            ['planCode' => $plan->getCode()->value, 'provider' => $provider->value]
        );

        return $subscription;
    }

    public function activateSubscription(UserSubscription $subscription, ?string $providerSubscriptionId = null): UserSubscription
    {
        $this->entityManager->wrapInTransaction(function () use ($subscription, $providerSubscriptionId): void {
            $user = $subscription->getUser();
            $current = $this->subscriptions->findCurrentForUser($user);

            if ($current instanceof UserSubscription && $current->getId() !== $subscription->getId()) {
                $current->setStatus(UserSubscriptionStatus::CANCELLED);
                $current->setCancelledAt(new \DateTimeImmutable());
                $this->eventLogger->log(
                    $user,
                    $current,
                    SubscriptionEventType::SUBSCRIPTION_CANCELLED,
                    UserSubscriptionStatus::ACTIVE->value,
                    UserSubscriptionStatus::CANCELLED->value,
                    ['reason' => 'plan_replaced']
                );
            }

            $previousStatus = $subscription->getStatus()->value;
            $subscription
                ->setStatus(UserSubscriptionStatus::ACTIVE)
                ->setProviderSubscriptionId($providerSubscriptionId);

            $this->eventLogger->log(
                $user,
                $subscription,
                SubscriptionEventType::SUBSCRIPTION_ACTIVATED,
                $previousStatus,
                UserSubscriptionStatus::ACTIVE->value,
                ['planCode' => $subscription->getPlan()->getCode()->value]
            );
            $this->notifications->notify($user, 'subscription.activated', [
                'planCode' => $subscription->getPlan()->getCode()->value,
            ]);
        });
        $this->entityManager->flush();

        return $subscription;
    }

    public function cancelSubscription(UserAccount $user): UserSubscription
    {
        $subscription = $this->getActiveSubscription($user);
        $oldStatus = $subscription->getStatus()->value;
        $subscription
            ->setStatus(UserSubscriptionStatus::CANCELLED)
            ->setCancelledAt(new \DateTimeImmutable())
            ->setAutoRenew(false);

        $this->eventLogger->log(
            $user,
            $subscription,
            SubscriptionEventType::SUBSCRIPTION_CANCELLED,
            $oldStatus,
            UserSubscriptionStatus::CANCELLED->value
        );
        $this->fallbackToFreePlan($user);
        $this->entityManager->flush();

        return $subscription;
    }

    public function renewSubscription(UserAccount $user): UserSubscription
    {
        $current = $this->getActiveSubscription($user);
        $baseDate = $current->getCurrentPeriodEnd() > new \DateTimeImmutable()
            ? $current->getCurrentPeriodEnd()
            : new \DateTimeImmutable();
        $nextPeriodEnd = $baseDate->modify(sprintf('+%d days', $current->getPlan()->getDurationDays()));
        if (!$nextPeriodEnd instanceof \DateTimeImmutable) {
            throw new \RuntimeException('Impossible de renouveler l’abonnement.');
        }

        $current
            ->setStatus(UserSubscriptionStatus::ACTIVE)
            ->setCurrentPeriodStart($baseDate)
            ->setCurrentPeriodEnd($nextPeriodEnd)
            ->setExpiresAt($nextPeriodEnd);
        $this->eventLogger->log(
            $user,
            $current,
            SubscriptionEventType::SUBSCRIPTION_RENEWED,
            null,
            UserSubscriptionStatus::ACTIVE->value,
            ['planCode' => $current->getPlan()->getCode()->value]
        );
        $this->entityManager->flush();

        return $current;
    }

    public function changePlan(UserAccount $user, SubscriptionPlan $plan, PaymentProvider $provider): UserSubscription
    {
        return $this->createPendingSubscription($user, $plan, $provider);
    }

    /**
     * @return list<UserSubscription>
     */
    public function expireSubscriptions(): array
    {
        $expiredSubscriptions = [];
        $now = new \DateTimeImmutable();

        foreach ($this->subscriptions->findExpiredActiveSubscriptions($now) as $subscription) {
            $oldStatus = $subscription->getStatus()->value;
            $subscription->setStatus(UserSubscriptionStatus::EXPIRED);
            $expiredSubscriptions[] = $subscription;

            $this->eventLogger->log(
                $subscription->getUser(),
                $subscription,
                SubscriptionEventType::SUBSCRIPTION_EXPIRED,
                $oldStatus,
                UserSubscriptionStatus::EXPIRED->value
            );

            $this->fallbackToFreePlan($subscription->getUser());
            $this->notifications->notify($subscription->getUser(), 'subscription.expired', [
                'planCode' => $subscription->getPlan()->getCode()->value,
            ]);
        }
        $this->entityManager->flush();

        return $expiredSubscriptions;
    }

    public function fallbackToFreePlan(UserAccount $user): UserSubscription
    {
        $current = $this->subscriptions->findCurrentForUser($user);
        if ($current instanceof UserSubscription) {
            return $current;
        }

        $freePlan = $this->getPlanByCode(SubscriptionPlanCode::FREE->value);
        $subscription = $this->buildSubscription($user, $freePlan, UserSubscriptionStatus::ACTIVE, null);
        $this->entityManager->persist($subscription);
        $this->entityManager->flush();

        return $subscription;
    }

    public function getUser(int $userId): UserAccount
    {
        $user = $this->users->find($userId);
        if (!$user instanceof UserAccount) {
            throw new \RuntimeException(sprintf('Utilisateur %d introuvable.', $userId));
        }

        return $user;
    }

    public function getPlanByCode(string $planCode): SubscriptionPlan
    {
        try {
            $code = SubscriptionPlanCode::from(strtoupper($planCode));
        } catch (\ValueError) {
            throw new InvalidSubscriptionPlanException('Plan d’abonnement invalide.');
        }

        $plan = $this->plans->findOneBy([
            'code' => $code,
            'isActive' => true,
        ]);

        if (!$plan instanceof SubscriptionPlan) {
            throw new InvalidSubscriptionPlanException('Plan d’abonnement introuvable ou inactif.');
        }

        return $plan;
    }

    private function buildSubscription(
        UserAccount $user,
        SubscriptionPlan $plan,
        UserSubscriptionStatus $status,
        ?PaymentProvider $provider
    ): UserSubscription {
        $now = new \DateTimeImmutable();
        $periodEnd = $now->modify(sprintf('+%d days', $plan->getDurationDays()));
        if (!$periodEnd instanceof \DateTimeImmutable) {
            throw new \RuntimeException('Impossible de calculer la période de souscription.');
        }

        return (new UserSubscription())
            ->setUser($user)
            ->setPlan($plan)
            ->setStatus($status)
            ->setStartedAt($now)
            ->setCurrentPeriodStart($now)
            ->setCurrentPeriodEnd($periodEnd)
            ->setExpiresAt($periodEnd)
            ->setPaymentProvider($provider);
    }
}
