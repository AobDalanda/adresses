<?php

namespace App\Service\Subscription;

use App\Entity\SubscriptionPlan;
use App\Entity\UserAccount;
use App\Exception\SubscriptionLimitReachedException;
use App\Repository\SubscriptionPlanRepository;
use App\Service\UserAddressService;
use Doctrine\DBAL\Connection;

final class PlanLimitChecker
{
    public function __construct(
        private readonly SubscriptionManager $subscriptions,
        private readonly UsageCounterManager $usageCounters,
        private readonly SubscriptionPlanRepository $plans,
        private readonly UserAddressService $userAddresses,
        private readonly Connection $db,
        private readonly SubscriptionEventLogger $eventLogger
    ) {
    }

    public function assertCanCreateAddress(UserAccount $user): void
    {
        $subscription = $this->subscriptions->getActiveSubscription($user);
        $plan = $subscription->getPlan();
        $used = $this->countUserAddresses((int) $user->getId());

        if ($plan->getMaxAddresses() !== null && $used >= $plan->getMaxAddresses()) {
            $requiredPlan = $this->findMinimumPlanForLimit('maxAddresses', $used + 1);
            $this->eventLogger->log($user, $subscription, \App\Enum\SubscriptionEventType::LIMIT_REACHED, null, null, [
                'feature' => 'addresses',
                'used' => $used,
                'limit' => $plan->getMaxAddresses(),
                'requiredPlan' => $requiredPlan,
            ]);

            throw new SubscriptionLimitReachedException(
                'Votre abonnement ne permet pas de creer une nouvelle adresse.',
                $requiredPlan
            );
        }
    }

    public function assertCanGenerateQrCode(UserAccount $user): void
    {
        $subscription = $this->subscriptions->getActiveSubscription($user);
        $plan = $subscription->getPlan();
        $used = $this->countGeneratedQrCodes((int) $user->getId());

        if ($plan->getMaxQrCodes() !== null && $used >= $plan->getMaxQrCodes()) {
            throw new SubscriptionLimitReachedException(
                'Votre abonnement ne permet pas de generer un nouveau QR Code.',
                $this->findMinimumPlanForLimit('maxQrCodes', $used + 1)
            );
        }
    }

    public function assertCanCreateDelivery(UserAccount $user): void
    {
        $subscription = $this->subscriptions->getActiveSubscription($user);
        $plan = $subscription->getPlan();
        $counter = $this->usageCounters->getCurrentCounter($user, $subscription);

        if ($plan->getMaxDeliveriesPerMonth() !== null && $counter->getDeliveriesCreated() >= $plan->getMaxDeliveriesPerMonth()) {
            throw new SubscriptionLimitReachedException(
                'Votre abonnement ne permet pas de creer une nouvelle livraison.',
                $this->findMinimumPlanForLimit('maxDeliveriesPerMonth', $counter->getDeliveriesCreated() + 1)
            );
        }
    }

    public function assertCanTrackDelivery(UserAccount $user): void
    {
        $plan = $this->subscriptions->getActiveSubscription($user)->getPlan();
        if (!$plan->canTrackDelivery()) {
            throw new SubscriptionLimitReachedException(
                'Le suivi temps reel n’est pas disponible avec votre abonnement.',
                $this->findMinimumFeaturePlan(static fn (SubscriptionPlan $candidate) => $candidate->canTrackDelivery())
            );
        }
    }

    public function assertCanCreateBusinessAddress(UserAccount $user): void
    {
        $plan = $this->subscriptions->getActiveSubscription($user)->getPlan();
        if (!$plan->canCreateBusinessAddress()) {
            throw new SubscriptionLimitReachedException(
                'Votre abonnement ne permet pas de creer une adresse professionnelle.',
                $this->findMinimumFeaturePlan(static fn (SubscriptionPlan $candidate) => $candidate->canCreateBusinessAddress())
            );
        }
    }

    public function buildUsageSummary(UserAccount $user): array
    {
        $subscription = $this->subscriptions->getActiveSubscription($user);
        $plan = $subscription->getPlan();
        $counter = $this->usageCounters->getCurrentCounter($user, $subscription);

        return [
            'addresses' => [
                'used' => $this->countUserAddresses((int) $user->getId()),
                'limit' => $plan->getMaxAddresses(),
            ],
            'qrCodes' => [
                'used' => $counter->getQrCodesGenerated(),
                'limit' => $plan->getMaxQrCodes(),
            ],
            'deliveriesThisMonth' => [
                'used' => $counter->getDeliveriesCreated(),
                'limit' => $plan->getMaxDeliveriesPerMonth(),
            ],
        ];
    }

    private function countUserAddresses(int $userId): int
    {
        return (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM user_address WHERE user_id = :userId',
            ['userId' => $userId]
        );
    }

    private function countGeneratedQrCodes(int $userId): int
    {
        return (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM address_qrcodes WHERE created_by = :userId',
            ['userId' => $userId]
        );
    }

    private function findMinimumPlanForLimit(string $field, int $requiredValue): string
    {
        foreach ($this->plans->findAllActive() as $plan) {
            $value = match ($field) {
                'maxAddresses' => $plan->getMaxAddresses(),
                'maxQrCodes' => $plan->getMaxQrCodes(),
                'maxDeliveriesPerMonth' => $plan->getMaxDeliveriesPerMonth(),
                default => null,
            };

            if ($value === null || $value >= $requiredValue) {
                return $plan->getCode()->value;
            }
        }

        return 'BUSINESS';
    }

    private function findMinimumFeaturePlan(callable $predicate): string
    {
        foreach ($this->plans->findAllActive() as $plan) {
            if ($predicate($plan)) {
                return $plan->getCode()->value;
            }
        }

        return 'BUSINESS';
    }
}
