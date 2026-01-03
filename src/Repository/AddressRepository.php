<?php

namespace App\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\ResultSetMapping;

class AddressRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * Trouver l’adresse la plus proche.
     */
    public function findNearest(float $lat, float $lng, int $radiusMeters = 10): ?array
    {
        $sql = "
            SELECT a.address_code,
                   ST_Distance(gc.centroid, point) AS distance
            FROM address a
            JOIN geo_cell gc ON a.geo_cell_id = gc.id,
                 ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)::geography AS point
            WHERE ST_DWithin(gc.centroid, point, :radius)
            ORDER BY distance ASC
            LIMIT 1
        ";

        $rsm = new ResultSetMapping();
        $rsm->addScalarResult('address_code', 'address_code');
        $rsm->addScalarResult('distance', 'distance');

        $query = $this->em->createNativeQuery($sql, $rsm);
        $query->setParameter('lat', $lat);
        $query->setParameter('lng', $lng);
        $query->setParameter('radius', $radiusMeters);

        return $query->getOneOrNullResult();
    }

    /**
     * Trouver la zone administrative.
     */
    public function findAdminArea(float $lat, float $lng): ?array
    {
        $sql = "
            SELECT name, type
            FROM geo_admin_area
            WHERE ST_Contains(
                boundary,
                ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)::geography
            )
            LIMIT 1
        ";

        $rsm = new ResultSetMapping();
        $rsm->addScalarResult('name', 'name');
        $rsm->addScalarResult('type', 'type');

        $query = $this->em->createNativeQuery($sql, $rsm);
        $query->setParameter('lat', $lat);
        $query->setParameter('lng', $lng);

        return $query->getOneOrNullResult();
    }
}
