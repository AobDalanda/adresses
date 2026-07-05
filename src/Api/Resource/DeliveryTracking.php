<?php

declare(strict_types=1);

namespace App\Api\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use App\Api\Controller\DeliveryAcceptAction;
use App\Api\Controller\DeliveryTrackingAuthorizationAction;
use App\Api\Controller\DeliveryTrackingStateAction;
use App\Api\Controller\DeliveryUpdateStatusAction;

#[ApiResource(operations: [
    new Post(
        uriTemplate: '/deliveries/{publicId}/accept',
        controller: DeliveryAcceptAction::class,
        read: false,
        deserialize: false,
        output: false,
        name: 'app_delivery_accept',
    ),
    new Get(
        uriTemplate: '/deliveries/{publicId}/tracking',
        controller: DeliveryTrackingStateAction::class,
        read: false,
        deserialize: false,
        output: false,
        name: 'app_delivery_tracking_state',
    ),
    new Post(
        uriTemplate: '/deliveries/{publicId}/tracking-authorization',
        controller: DeliveryTrackingAuthorizationAction::class,
        read: false,
        deserialize: false,
        output: false,
        name: 'app_delivery_tracking_authorization',
    ),
    new Post(
        uriTemplate: '/deliveries/{publicId}/status',
        requirements: ['publicId' => '[0-9a-fA-F-]{36}'],
        controller: DeliveryUpdateStatusAction::class,
        read: false,
        deserialize: false,
        output: false,
        name: 'app_delivery_update_status',
    ),
])]
final class DeliveryTracking
{
}
