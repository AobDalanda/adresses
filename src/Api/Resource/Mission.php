<?php

declare(strict_types=1);

namespace App\Api\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Api\Controller\MissionDetailAction;
use App\Api\Controller\MissionListAction;

#[ApiResource(operations: [
    new GetCollection(
        uriTemplate: '/missions',
        controller: MissionListAction::class,
        read: false,
        deserialize: false,
        output: false,
        name: 'app_mission_list',
    ),
    new Get(
        uriTemplate: '/missions/{publicId}',
        requirements: ['publicId' => '[0-9a-fA-F-]{36}'],
        controller: MissionDetailAction::class,
        read: false,
        deserialize: false,
        output: false,
        name: 'app_mission_detail',
    ),
])]
final class Mission
{
}
