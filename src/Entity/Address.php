<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

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
