<?php

namespace App\Repository;

use App\Entity\SubscriptionPlan;
use App\Enum\SubscriptionPlanCode;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SubscriptionPlan>
 */
class SubscriptionPlanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SubscriptionPlan::class);
    }

    public function findActiveByCode(string $code): ?SubscriptionPlan
    {
        return $this->findOneBy([
            'code' => SubscriptionPlanCode::from($code),
            'isActive' => true,
        ]);
    }

    /**
     * @return list<SubscriptionPlan>
     */
    public function findAllActive(): array
    {
        return $this->createQueryBuilder('plan')
            ->andWhere('plan.isActive = :active')
            ->setParameter('active', true)
            ->orderBy('plan.priceAmount', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
