<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProviderAutomaticCheck;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProviderAutomaticCheck>
 */
final class ProviderAutomaticCheckRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProviderAutomaticCheck::class);
    }
}
