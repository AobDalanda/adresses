<?php

namespace App\Service\Subscription;

use App\Entity\SubscriptionPlan;
use App\Entity\UserAccount;
use App\Repository\SubscriptionPlanRepository;

final class MeSubscriptionViewFactory
{
    public function __construct(
        private readonly SubscriptionManager $subscriptions,
        private readonly PlanLimitChecker $planLimitChecker,
        private readonly SubscriptionPlanRepository $plans
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function create(UserAccount $user): array
    {
        $subscription = $this->subscriptions->getActiveSubscription($user);
        $plan = $subscription->getPlan();
        $usage = $this->planLimitChecker->buildUsageSummary($user);

        return [
            'success' => true,
            'subscription' => [
                'status' => $subscription->getStatus()->value,
                'plan' => [
                    'code' => $plan->getCode()->value,
                    'name' => $plan->getName(),
                    'priceAmount' => $plan->getPriceAmount(),
                    'currency' => $plan->getCurrency(),
                ],
                'currentPeriodStart' => $subscription->getCurrentPeriodStart()->format(\DateTimeInterface::ATOM),
                'currentPeriodEnd' => $subscription->getCurrentPeriodEnd()->format(\DateTimeInterface::ATOM),
                'expiresAt' => $subscription->getExpiresAt()->format(\DateTimeInterface::ATOM),
                'autoRenew' => $subscription->isAutoRenew(),
            ],
            'usage' => $usage,
            'features' => [
                'canTrackDelivery' => $plan->canTrackDelivery(),
                'canUseCustomQrCode' => $plan->canUseCustomQrCode(),
                'canCreateBusinessAddress' => $plan->canCreateBusinessAddress(),
            ],
            'availablePlans' => $this->buildPlansPayload(),
            'serverPlans' => $this->buildPlansPayload(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildPlansPayload(): array
    {
        return array_map(
            fn (SubscriptionPlan $plan): array => $this->serializePlan($plan),
            $this->plans->findAllActive()
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function serializePlan(SubscriptionPlan $plan): array
    {
        return [
            'code' => $plan->getCode()->value,
            'name' => $plan->getName(),
            'description' => $plan->getDescription(),
            'priceAmount' => $plan->getPriceAmount(),
            'currency' => $plan->getCurrency(),
            'durationDays' => $plan->getDurationDays(),
            'maxAddresses' => $plan->getMaxAddresses(),
            'maxQrCodes' => $plan->getMaxQrCodes(),
            'maxDeliveriesPerMonth' => $plan->getMaxDeliveriesPerMonth(),
            'canTrackDelivery' => $plan->canTrackDelivery(),
            'canUseCustomQrCode' => $plan->canUseCustomQrCode(),
            'canCreateBusinessAddress' => $plan->canCreateBusinessAddress(),
        ];
    }
}
