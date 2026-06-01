<?php

namespace App\Repository;

use App\Entity\UsageCounter;
use App\Entity\UserAccount;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UsageCounter>
 */
final class UsageCounterRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UsageCounter::class);
    }

    public function findOneForUserAndPeriod(UserAccount $user, \DateTimeImmutable $periodStart, \DateTimeImmutable $periodEnd): ?UsageCounter
    {
        return $this->findOneBy([
            'user' => $user,
            'periodStart' => $periodStart,
            'periodEnd' => $periodEnd,
        ]);
    }
}
