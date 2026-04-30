<?php

namespace App\Api\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Api\Controller\AddressCreateAction;

#[ApiResource(operations: [
    new Post(
        uriTemplate: '/address',
        controller: AddressCreateAction::class,
        read: false,
        deserialize: false,
        output: false,
        name: 'app_address_create'
    ),
])]
final class AddressCreate
{
}
