<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Api\State\AuthenticatedCollectionProvider;
use App\Api\State\AuthenticatedItemProvider;
use Doctrine\ORM\Mapping as ORM;

#[ApiResource(operations: [
    new Get(provider: AuthenticatedItemProvider::class),
    new GetCollection(provider: AuthenticatedCollectionProvider::class),
])]
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'geo_cell')]
class GeoCell
{
    #[ORM\Id]
    #[ORM\Column(type: 'bigint')]
    public int $id;

    #[ORM\Column(length: 32)]
    public string $cellCode;

    #[ORM\Column]
    public int $precisionM;

    // centroid / polygon -> utilises via SQL, pas mappes
}
