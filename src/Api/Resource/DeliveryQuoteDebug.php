<?php

namespace App\Api\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Api\Controller\DeliveryQuoteDebugAction;

#[ApiResource(operations: [
    new Post(
        uriTemplate: '/deliveries/quote/debug',
        controller: DeliveryQuoteDebugAction::class,
        read: false,
        deserialize: false,
        output: false,
        name: 'app_delivery_quote_debug'
    ),
])]
final class DeliveryQuoteDebug
{
}
