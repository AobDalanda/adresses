<?php

namespace App\Api\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Api\Controller\SubscriptionCheckoutAction;

#[ApiResource(operations: [
    new Post(
        uriTemplate: '/subscription/checkout',
        controller: SubscriptionCheckoutAction::class,
        read: false,
        deserialize: false,
        output: false,
        name: 'app_subscription_checkout'
    ),
])]
final class SubscriptionCheckout
{
}
