<?php

declare(strict_types=1);

namespace App\Api\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Api\Controller\NotificationListAction;

#[ApiResource(operations: [
    new Get(
        uriTemplate: '/notifications',
        controller: NotificationListAction::class,
        read: false,
        deserialize: false,
        output: false,
        name: 'app_notification_list'
    ),
])]
final class NotificationList
{
}
