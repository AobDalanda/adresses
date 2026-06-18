<?php

namespace App\Api\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Api\Controller\AuthBiometricLoginAction;

#[ApiResource(operations: [
    new Post(
        uriTemplate: '/auth/biometric-login',
        controller: AuthBiometricLoginAction::class,
        read: false,
        deserialize: false,
        output: false,
        name: 'app_auth_biometric_login'
    ),
])]
final class AuthBiometricLogin
{
}
