<?php

namespace App\Api\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Api\Controller\UserAddressSetDefaultAction;

#[ApiResource(operations: [
    new Post(
        uriTemplate: '/user/address/default',
        controller: UserAddressSetDefaultAction::class,
        read: false,
        deserialize: false,
        output: false,
        name: 'app_user_address_set_default'
    ),
])]
final class UserAddressSetDefault
{
}
