<?php

namespace App\Api\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Patch;
use App\Api\Controller\ProviderProfileGetAction;
use App\Api\Controller\ProviderProfileUpdateAction;

#[ApiResource(operations: [
    new Get(
        uriTemplate: '/provider/profile',
        controller: ProviderProfileGetAction::class,
        read: false,
        output: false,
        name: 'app_provider_profile_get'
    ),
    new Patch(
        uriTemplate: '/provider/profile',
        controller: ProviderProfileUpdateAction::class,
        read: false,
        deserialize: false,
        output: false,
        name: 'app_provider_profile_update'
    ),
])]
final class ProviderProfile
{
}
