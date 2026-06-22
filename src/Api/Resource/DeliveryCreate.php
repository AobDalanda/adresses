<?php

namespace App\Api\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Api\Controller\DeliveryCreateAction;
use App\Api\Controller\DeliveryMercureAuthorizationAction;

#[ApiResource(operations: [
    new Post(
        uriTemplate: '/deliveries',
        controller: DeliveryCreateAction::class,
        read: false,
        deserialize: false,
        output: false,
        name: 'app_delivery_create'
    ),
    new Post(
        uriTemplate: '/deliveries/mercure-authorization',
        controller: DeliveryMercureAuthorizationAction::class,
        read: false,
        deserialize: false,
        output: false,
        name: 'app_delivery_mercure_authorization'
    ),
])]
final class DeliveryCreate
{
}
