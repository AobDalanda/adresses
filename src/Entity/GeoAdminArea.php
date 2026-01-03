<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

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
