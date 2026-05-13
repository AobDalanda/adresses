<?php

namespace App\Api\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Api\Controller\UserOverviewAction;

#[ApiResource(operations: [
    new Get(
        uriTemplate: '/user/overview',
        controller: UserOverviewAction::class,
        read: false,
        deserialize: false,
        output: false,
        name: 'app_user_overview'
    ),
])]
final class UserOverview
{
}
