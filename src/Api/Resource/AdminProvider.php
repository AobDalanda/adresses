<?php

namespace App\Api\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use App\Api\Controller\AdminProviderDetailAction;
use App\Api\Controller\AdminProviderListAction;
use App\Api\Controller\AdminProviderStatusAction;

#[ApiResource(operations: [
    new GetCollection(
        uriTemplate: '/admin/providers',
        controller: AdminProviderListAction::class,
        read: false,
        output: false,
        paginationEnabled: false,
        name: 'app_admin_provider_list'
    ),
    new Get(
        uriTemplate: '/admin/providers/{id}',
        controller: AdminProviderDetailAction::class,
        read: false,
        output: false,
        requirements: ['id' => '\d+'],
        name: 'app_admin_provider_detail'
    ),
    new Patch(
        uriTemplate: '/admin/providers/{id}/status',
        controller: AdminProviderStatusAction::class,
        read: false,
        deserialize: false,
        output: false,
        requirements: ['id' => '\d+'],
        name: 'app_admin_provider_status'
    ),
])]
final class AdminProvider
{
}
