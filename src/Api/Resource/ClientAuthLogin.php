<?php

namespace App\Api\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Api\Controller\ClientAuthLoginAction;

#[ApiResource(operations: [
    new Post(
        uriTemplate: '/auth/client/login',
        controller: ClientAuthLoginAction::class,
        read: false,
        deserialize: false,
        output: false,
        name: 'app_clientauth_login'
    ),
])]
final class ClientAuthLogin
{
}
