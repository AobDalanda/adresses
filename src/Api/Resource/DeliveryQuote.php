<?php

namespace App\Api\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Api\Controller\DeliveryQuoteAction;

#[ApiResource(operations: [
    new Post(
        uriTemplate: '/deliveries/quote',
        controller: DeliveryQuoteAction::class,
        read: false,
        deserialize: false,
        output: false,
        name: 'app_delivery_quote'
    ),
])]
final class DeliveryQuote
{
}
