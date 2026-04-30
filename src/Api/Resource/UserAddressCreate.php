<?php

namespace App\Api\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Api\Controller\UserAddressCreateAction;

#[ApiResource(operations: [
    new Post(
        uriTemplate: '/user/address/create',
        controller: UserAddressCreateAction::class,
        read: false,
        deserialize: false,
        output: false,
        name: 'app_user_address_create'
    ),
])]
final class UserAddressCreate
{
}
