<?php

namespace App\Api\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Patch;
use App\Api\Controller\UserProfileUpdateAction;

#[ApiResource(operations: [
    new Patch(
        uriTemplate: '/user/me',
        controller: UserProfileUpdateAction::class,
        read: false,
        deserialize: false,
        output: false,
        name: 'app_user_profile_update'
    ),
])]
final class UserProfileUpdate
{
}
