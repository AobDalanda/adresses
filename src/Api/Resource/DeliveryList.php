<?php

namespace App\Api\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use App\Api\Controller\DeliveryListAction;

#[ApiResource(operations: [
    new GetCollection(
        uriTemplate: '/deliveries',
        controller: DeliveryListAction::class,
        read: false,
        deserialize: false,
        output: false,
        name: 'app_delivery_list'
    ),
])]
final class DeliveryList
{
}
