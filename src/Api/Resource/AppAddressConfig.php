<?php

namespace App\Api\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Api\Controller\AppAddressConfigAction;

#[ApiResource(operations: [
    new Get(
        uriTemplate: '/app/address-config',
        controller: AppAddressConfigAction::class,
        read: false,
        deserialize: false,
        output: false,
        name: 'app_address_config'
    ),
])]
final class AppAddressConfig
{
}
