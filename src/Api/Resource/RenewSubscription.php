<?php

namespace App\Api\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Api\Controller\RenewSubscriptionAction;

#[ApiResource(operations: [
    new Post(
        uriTemplate: '/me/subscription/renew',
        controller: RenewSubscriptionAction::class,
        read: false,
        deserialize: false,
        output: false,
        name: 'app_subscription_renew'
    ),
])]
final class RenewSubscription
{
}
