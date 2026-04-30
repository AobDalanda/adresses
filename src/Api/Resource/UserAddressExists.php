<?php

namespace App\Api\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Api\Controller\UserAddressExistsAction;

#[ApiResource(operations: [
    new Get(
        uriTemplate: '/user/address/exists',
        controller: UserAddressExistsAction::class,
        read: false,
        deserialize: false,
        output: false,
        name: 'app_user_address_exists'
    ),
])]
final class UserAddressExists
{
}
