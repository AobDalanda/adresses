<?php

namespace App\Api\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Api\Controller\UserAccountRegisterAction;

#[ApiResource(operations: [
    new Post(
        uriTemplate: '/auth/register',
        controller: UserAccountRegisterAction::class,
        read: false,
        deserialize: false,
        output: false,
        name: 'app_useraccount_register'
    ),
])]
final class UserAccountRegister
{
}
