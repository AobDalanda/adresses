<?php

namespace App\Api\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Api\Controller\AuthRefreshTokenAction;

#[ApiResource(operations: [
    new Post(
        uriTemplate: '/auth/refresh-token',
        controller: AuthRefreshTokenAction::class,
        read: false,
        deserialize: false,
        output: false,
        name: 'app_auth_refresh_token'
    ),
])]
final class AuthRefreshToken
{
}
