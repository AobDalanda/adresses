<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProviderApplication;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProviderApplication>
 */
final class ProviderApplicationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProviderApplication::class);
    }
}
