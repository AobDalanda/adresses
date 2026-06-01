<?php

namespace App\Api\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Api\Controller\CheckoutSubscriptionAction;

#[ApiResource(operations: [
    new Post(
        uriTemplate: '/me/subscription/checkout',
        controller: CheckoutSubscriptionAction::class,
        read: false,
        deserialize: false,
        output: false,
        name: 'app_subscription_checkout_v2'
    ),
])]
final class CheckoutSubscription
{
}
