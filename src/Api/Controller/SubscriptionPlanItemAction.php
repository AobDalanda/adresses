<?php

namespace App\Api\Controller;

use App\Exception\InvalidSubscriptionPlanException;
use App\Service\Subscription\SubscriptionManager;
use Symfony\Component\HttpFoundation\JsonResponse;

final class SubscriptionPlanItemAction
{
    public function __construct(private readonly SubscriptionManager $subscriptions)
    {
    }

    public function __invoke(string $code): JsonResponse
    {
        try {
            $plan = $this->subscriptions->getPlanByCode($code);
        } catch (InvalidSubscriptionPlanException) {
            return new JsonResponse(['message' => 'Plan introuvable'], 404);
        }

        return new JsonResponse([
            'success' => true,
            'plan' => [
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
            ],
        ]);
    }
}
