<?php

namespace App\Api\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Api\Controller\AuthOtpRequestAction;

#[ApiResource(operations: [
    new Post(
        uriTemplate: '/auth/otp/request',
        controller: AuthOtpRequestAction::class,
        read: false,
        deserialize: false,
        output: false,
        name: 'app_auth_requestotp'
    ),
])]
final class AuthOtpRequest
{
}
