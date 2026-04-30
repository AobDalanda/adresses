<?php

namespace App\Api\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Api\Controller\UserAddressRegisterAction;

#[ApiResource(operations: [
    new Post(
        uriTemplate: '/user/address',
        controller: UserAddressRegisterAction::class,
        read: false,
        deserialize: false,
        output: false,
        name: 'app_user_address_register'
    ),
])]
final class UserAddressRegister
{
}
