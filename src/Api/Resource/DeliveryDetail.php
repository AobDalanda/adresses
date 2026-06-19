<?php

namespace App\Api\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Api\Controller\DeliveryDetailAction;

#[ApiResource(operations: [
    new Get(
        uriTemplate: '/deliveries/{publicId}',
        controller: DeliveryDetailAction::class,
        read: false,
        deserialize: false,
        output: false,
        name: 'app_delivery_detail'
    ),
])]
final class DeliveryDetail
{
}
