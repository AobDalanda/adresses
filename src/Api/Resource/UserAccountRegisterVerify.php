<?php

namespace App\Api\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Api\Controller\UserAccountRegisterVerifyAction;

#[ApiResource(operations: [
    new Post(
        uriTemplate: '/auth/register/verify',
        controller: UserAccountRegisterVerifyAction::class,
        read: false,
        deserialize: false,
        output: false,
        name: 'app_useraccount_register_verify'
    ),
])]
final class UserAccountRegisterVerify
{
}
