<?php

declare(strict_types=1);

namespace App\Api\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Api\Controller\AdminDeliveryMatchingDiagnosticsAction;

#[ApiResource(operations: [
    new Get(
        uriTemplate: '/admin/deliveries/{deliveryId}/matching-diagnostics',
        controller: AdminDeliveryMatchingDiagnosticsAction::class,
        read: false,
        deserialize: false,
        output: false,
        requirements: ['deliveryId' => '[0-9a-fA-F-]{36}'],
        name: 'app_admin_delivery_matching_diagnostics'
    ),
])]
final class AdminDeliveryMatchingDiagnostic
{
}
