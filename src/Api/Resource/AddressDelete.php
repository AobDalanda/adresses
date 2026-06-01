<?php

namespace App\Api\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use App\Api\Controller\UserAddressDeleteAction;

#[ApiResource(operations: [
    new Delete(
        uriTemplate: '/addresses/{id}',
        controller: UserAddressDeleteAction::class,
        read: false,
        deserialize: false,
        output: false,
        requirements: ['id' => '\d+'],
        name: 'app_address_delete'
    ),
])]
final class AddressDelete
{
}
