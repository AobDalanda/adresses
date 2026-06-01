<?php

namespace App\Api\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Api\Controller\CancelSubscriptionAction;

#[ApiResource(operations: [
    new Post(
        uriTemplate: '/me/subscription/cancel',
        controller: CancelSubscriptionAction::class,
        read: false,
        deserialize: false,
        output: false,
        name: 'app_subscription_cancel'
    ),
])]
final class CancelSubscription
{
}
