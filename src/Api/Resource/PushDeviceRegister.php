<?php

declare(strict_types=1);

namespace App\Api\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Put;
use App\Api\Controller\PushDeviceRegisterAction;

#[ApiResource(operations: [
    new Put(
        uriTemplate: '/notifications/devices',
        controller: PushDeviceRegisterAction::class,
        read: false,
        deserialize: false,
        output: false,
        name: 'app_push_device_register'
    ),
])]
final class PushDeviceRegister
{
}
