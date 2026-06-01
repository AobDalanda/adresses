<?php

namespace App\Repository;

use App\Entity\UserSubscription;
use App\Entity\UserAccount;
use App\Enum\UserSubscriptionStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserSubscription>
 */
final class UserSubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserSubscription::class);
    }

    public function findCurrentForUser(UserAccount $user): ?UserSubscription
    {
        return $this->createQueryBuilder('subscription')
            ->andWhere('subscription.user = :user')
            ->andWhere('subscription.status IN (:statuses)')
            ->setParameter('user', $user)
            ->setParameter('statuses', [
                UserSubscriptionStatus::ACTIVE,
                UserSubscriptionStatus::TRIALING,
                UserSubscriptionStatus::PAST_DUE,
            ])
            ->orderBy('subscription.currentPeriodEnd', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findLatestPendingForUser(UserAccount $user): ?UserSubscription
    {
        return $this->createQueryBuilder('subscription')
            ->andWhere('subscription.user = :user')
            ->andWhere('subscription.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', UserSubscriptionStatus::PENDING_PAYMENT)
            ->orderBy('subscription.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<UserSubscription>
     */
    public function findExpiredActiveSubscriptions(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('subscription')
            ->andWhere('subscription.status IN (:statuses)')
            ->andWhere('subscription.expiresAt < :now')
            ->setParameter('statuses', [
                UserSubscriptionStatus::ACTIVE,
                UserSubscriptionStatus::TRIALING,
                UserSubscriptionStatus::PAST_DUE,
            ])
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();
    }
}
