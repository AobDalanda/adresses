<?php

declare(strict_types=1);

namespace App\Api\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use App\Api\Controller\DriverLocationHistoryAction;
use App\Api\Controller\DriverLocationLastAction;
use App\Api\Controller\DriverLocationMercureAuthorizationAction;
use App\Api\Controller\DriverLocationUpdateAction;

#[ApiResource(operations: [
    new Post(
        uriTemplate: '/drivers/location',
        controller: DriverLocationUpdateAction::class,
        read: false,
        deserialize: false,
        output: false,
        openapi: new Operation(
            tags: ['Driver tracking'],
            summary: 'Publie la position GPS du livreur authentifie',
            description: 'Publie ensuite la position sur le topic Mercure driver/{driverId}/location.'
        ),
        name: 'app_driver_location_update'
    ),
    new Get(
        uriTemplate: '/drivers/{id}/location',
        controller: DriverLocationLastAction::class,
        read: false,
        deserialize: false,
        output: false,
        requirements: ['id' => '\d+'],
        openapi: new Operation(
            tags: ['Driver tracking'],
            summary: 'Retourne la derniere position connue'
        ),
        name: 'app_driver_location_last'
    ),
    new GetCollection(
        uriTemplate: '/drivers/{id}/locations',
        controller: DriverLocationHistoryAction::class,
        read: false,
        deserialize: false,
        output: false,
        paginationEnabled: false,
        requirements: ['id' => '\d+'],
        openapi: new Operation(
            tags: ['Driver tracking'],
            summary: 'Retourne l historique GPS pagine',
            description: 'Filtres disponibles: from, to et limit (1 a 1000).'
        ),
        name: 'app_driver_location_history'
    ),
    new Post(
        uriTemplate: '/drivers/{id}/mercure-authorization',
        controller: DriverLocationMercureAuthorizationAction::class,
        read: false,
        deserialize: false,
        output: false,
        requirements: ['id' => '\d+'],
        openapi: new Operation(
            tags: ['Driver tracking'],
            summary: 'Autorise un abonnement au topic Mercure prive du livreur',
            description: 'Emet un cookie HTTP-only mercureAuthorization limite au topic driver/{id}/location.'
        ),
        name: 'app_driver_location_mercure_authorization'
    ),
])]
final class DriverTracking
{
}
