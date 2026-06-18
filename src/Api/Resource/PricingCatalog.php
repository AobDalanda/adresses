<?php

namespace App\Api\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Api\Controller\PricingCatalogAction;

#[ApiResource(operations: [
    new Get(
        uriTemplate: '/pricing/catalog',
        controller: PricingCatalogAction::class,
        read: false,
        deserialize: false,
        output: false,
        name: 'app_pricing_catalog'
    ),
])]
final class PricingCatalog
{
}
