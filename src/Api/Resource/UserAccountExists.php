<?php

namespace App\Api\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Api\Controller\UserAccountExistsAction;

#[ApiResource(operations: [
    new Post(
        uriTemplate: '/auth/register/exists',
        controller: UserAccountExistsAction::class,
        read: false,
        deserialize: false,
        output: false,
        name: 'app_useraccount_exists'
    ),
])]
final class UserAccountExists
{
}
