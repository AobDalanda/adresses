<?php

namespace App\Api\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Api\Controller\AddressLookupAction;

#[ApiResource(operations: [
    new Get(
        uriTemplate: '/address/lookup',
        controller: AddressLookupAction::class,
        read: false,
        deserialize: false,
        output: false,
        name: 'app_address_lookup'
    ),
])]
final class AddressLookup
{
}
