<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProviderAuthorization;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProviderAuthorization>
 */
final class ProviderAuthorizationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProviderAuthorization::class);
    }
}
