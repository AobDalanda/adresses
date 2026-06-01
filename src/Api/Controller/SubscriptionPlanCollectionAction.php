<?php

namespace App\Api\Controller;

use App\Repository\SubscriptionPlanRepository;
use Symfony\Component\HttpFoundation\JsonResponse;

final class SubscriptionPlanCollectionAction
{
    public function __construct(private readonly SubscriptionPlanRepository $plans)
    {
    }

    public function __invoke(): JsonResponse
    {
        $payload = array_map(static function ($plan): array {
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
        }, $this->plans->findAllActive());

        return new JsonResponse([
            'success' => true,
            'plans' => $payload,
        ]);
    }
}
