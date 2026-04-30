<?php

namespace App\Api\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Patch;
use App\Api\Controller\UserAddressUpdateLocationAction;

#[ApiResource(operations: [
    new Patch(
        uriTemplate: '/user/address/current',
        controller: UserAddressUpdateLocationAction::class,
        read: false,
        deserialize: false,
        output: false,
        name: 'app_user_address_update_current'
    ),
])]
final class UserAddressUpdateLocation
{
}
