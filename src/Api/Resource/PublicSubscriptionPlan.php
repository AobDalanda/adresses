<?php

namespace App\Api\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Api\Controller\SubscriptionPlanCollectionAction;
use App\Api\Controller\SubscriptionPlanItemAction;

#[ApiResource(operations: [
    new GetCollection(
        uriTemplate: '/subscription-plans',
        controller: SubscriptionPlanCollectionAction::class,
        read: false,
        deserialize: false,
        output: false,
        name: 'app_subscription_plan_collection'
    ),
    new Get(
        uriTemplate: '/subscription-plans/{code}',
        controller: SubscriptionPlanItemAction::class,
        read: false,
        deserialize: false,
        output: false,
        name: 'app_subscription_plan_item'
    ),
])]
final class PublicSubscriptionPlan
{
}
