<?php

namespace App\Api\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Api\Controller\ChangeSubscriptionPlanAction;

#[ApiResource(operations: [
    new Post(
        uriTemplate: '/me/subscription/change-plan',
        controller: ChangeSubscriptionPlanAction::class,
        read: false,
        deserialize: false,
        output: false,
        name: 'app_subscription_change_plan'
    ),
])]
final class ChangeSubscriptionPlan
{
}
