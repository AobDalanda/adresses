<?php

namespace App\Api\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Api\Controller\AuthOtpVerifyAction;

#[ApiResource(operations: [
    new Post(
        uriTemplate: '/auth/otp/verify',
        controller: AuthOtpVerifyAction::class,
        read: false,
        deserialize: false,
        output: false,
        name: 'app_auth_verifyotp'
    ),
])]
final class AuthOtpVerify
{
}
