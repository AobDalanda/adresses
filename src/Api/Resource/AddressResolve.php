<?php

namespace App\Api\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Api\Controller\AddressResolveAction;

#[ApiResource(operations: [
    new Post(
        uriTemplate: '/address/resolve',
        controller: AddressResolveAction::class,
        read: false,
        deserialize: false,
        output: false,
        name: 'app_address_resolve'
    ),
])]
final class AddressResolve
{
}
