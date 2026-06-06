<?php

namespace App\Api\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Api\Controller\DriverRegistrationAction;

#[ApiResource(operations: [
    new Post(
        uriTemplate: '/user/register/driver',
        controller: DriverRegistrationAction::class,
        read: false,
        deserialize: false,
        output: false,
        name: 'app_user_register_driver'
    ),
])]
final class DriverRegistration
{
}
