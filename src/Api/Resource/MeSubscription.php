<?php

namespace App\Api\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Api\Controller\MeSubscriptionAction;

#[ApiResource(operations: [
    new Get(
        uriTemplate: '/me/subscription',
        controller: MeSubscriptionAction::class,
        read: false,
        deserialize: false,
        output: false,
        name: 'app_me_subscription'
    ),
])]
final class MeSubscription
{
}
