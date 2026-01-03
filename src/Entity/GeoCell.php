<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

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
