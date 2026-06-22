<?php

declare(strict_types=1);

namespace App\Api\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Put;
use App\Api\Controller\NotificationMarkReadAction;

#[ApiResource(operations: [
    new Put(
        uriTemplate: '/notifications/{id}/read',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        controller: NotificationMarkReadAction::class,
        read: false,
        deserialize: false,
        output: false,
        name: 'app_notification_mark_read'
    ),
])]
final class NotificationMarkRead
{
}
