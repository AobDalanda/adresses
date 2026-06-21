<?php

namespace App\Api\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Api\Controller\ProviderApprovalStatusGetAction;

#[ApiResource(operations: [
    new Get(
        uriTemplate: '/provider/approval-status',
        controller: ProviderApprovalStatusGetAction::class,
        read: false,
        deserialize: false,
        output: false,
        name: 'app_provider_approval_status_get'
    ),
])]
final class ProviderApprovalStatus
{
}
