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
#[ORM\Table(name: 'address')]
class Address
{
    #[ORM\Id]
    #[ORM\Column(type: 'bigint')]
    public int $id;

    #[ORM\Column(length: 50)]
    public string $addressCode;

    #[ORM\Column(nullable: true)]
    public ?string $phoneDisplay;

    #[ORM\Column(type: 'datetime')]
    public \DateTimeInterface $createdAt;
}
