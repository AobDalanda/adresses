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
#[ORM\Table(name: 'geo_admin_area')]
class GeoAdminArea
{
    #[ORM\Id]
    #[ORM\Column]
    public int $id;

    #[ORM\Column(length: 100)]
    public string $name;

    #[ORM\Column(length: 50)]
    public string $type;
}
