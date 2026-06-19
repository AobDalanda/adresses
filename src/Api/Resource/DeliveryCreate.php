<?php

namespace App\Api\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Api\Controller\DeliveryCreateAction;

#[ApiResource(operations: [
    new Post(
        uriTemplate: '/api/v1/deliveries',
        controller: DeliveryCreateAction::class,
        read: false,
        deserialize: false,
        output: false,
        name: 'app_delivery_create'
    ),
])]
final class DeliveryCreate
{
}
