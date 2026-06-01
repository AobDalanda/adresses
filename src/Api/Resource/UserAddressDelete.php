<?php

namespace App\Api\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use App\Api\Controller\UserAddressDeleteAction;

#[ApiResource(operations: [
    new Delete(
        uriTemplate: '/user/address/{addressId}',
        controller: UserAddressDeleteAction::class,
        read: false,
        deserialize: false,
        output: false,
        requirements: ['addressId' => '\d+'],
        name: 'app_user_address_delete'
    ),
])]
final class UserAddressDelete
{
}
