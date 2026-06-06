<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DriverLocation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DriverLocation>
 */
final class DriverLocationRepository extends ServiceEntityRepository implements DriverLocationRepositoryInterface
{
    private Connection $connection;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DriverLocation::class);
        $this->connection = $this->getEntityManager()->getConnection();
    }

    public function save(DriverLocation $location): void
    {
        $this->getEntityManager()->persist($location);
        $this->getEntityManager()->flush();
    }

    public function findLastForDriver(int $driverId): ?DriverLocation
    {
        return $this->findOneBy(['driverId' => $driverId], ['createdAt' => 'DESC', 'id' => 'DESC']);
    }

    public function findHistory(
        int $driverId,
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $to,
        int $limit
    ): array {
        $query = $this->createQueryBuilder('location')
            ->andWhere('location.driverId = :driverId')
            ->setParameter('driverId', $driverId)
            ->orderBy('location.createdAt', 'DESC')
            ->addOrderBy('location.id', 'DESC')
            ->setMaxResults($limit);

        if ($from !== null) {
            $query->andWhere('location.createdAt >= :from')->setParameter('from', $from);
        }
        if ($to !== null) {
            $query->andWhere('location.createdAt <= :to')->setParameter('to', $to);
        }

        return $query->getQuery()->getResult();
    }

    public function calculateDistance(
        int $driverId,
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $to
    ): float {
        [$where, $parameters, $types] = $this->buildPeriodFilter($driverId, $from, $to);
        $sql = sprintf(
            'SELECT COALESCE(SUM(ST_Distance(position, previous_position)), 0)
             FROM (
                SELECT position, LAG(position) OVER (ORDER BY created_at, id) AS previous_position
                FROM driver_location
                WHERE %s
             ) points
             WHERE previous_position IS NOT NULL',
            $where
        );

        return (float) $this->connection->fetchOne($sql, $parameters, $types);
    }

    public function calculateAverageSpeed(
        int $driverId,
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $to
    ): ?float {
        [$where, $parameters, $types] = $this->buildPeriodFilter($driverId, $from, $to);
        $value = $this->connection->fetchOne(
            sprintf('SELECT AVG(speed) FROM driver_location WHERE speed IS NOT NULL AND %s', $where),
            $parameters,
            $types
        );

        return $value === false || $value === null ? null : (float) $value;
    }

    /**
     * @return array{0: string, 1: array<string, int|string>, 2: array<string, ParameterType>}
     */
    private function buildPeriodFilter(
        int $driverId,
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $to
    ): array {
        $clauses = ['driver_id = :driverId'];
        $parameters = ['driverId' => $driverId];
        $types = ['driverId' => ParameterType::INTEGER];

        if ($from !== null) {
            $clauses[] = 'created_at >= :from';
            $parameters['from'] = $from->format('Y-m-d H:i:sP');
            $types['from'] = ParameterType::STRING;
        }
        if ($to !== null) {
            $clauses[] = 'created_at <= :to';
            $parameters['to'] = $to->format('Y-m-d H:i:sP');
            $types['to'] = ParameterType::STRING;
        }

        return [implode(' AND ', $clauses), $parameters, $types];
    }
}
