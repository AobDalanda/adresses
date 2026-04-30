<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

class UsageService
{
    public function __construct(private Connection $db)
    {
    }

    public function incrementLookupUsage(int $clientId, array $subscription, int $quota): ?int
    {
        $periodStart = (new \DateTimeImmutable($subscription['current_period_start']))->format('Y-m-d');
        $periodEnd = (new \DateTimeImmutable($subscription['current_period_end']))->format('Y-m-d');

        $count = $this->db->fetchOne(
            "
            INSERT INTO api_usage (client_id, period_start, period_end, count)
            VALUES (:clientId, :start, :end, 1)
            ON CONFLICT (client_id, period_start, period_end) DO UPDATE
                SET count = api_usage.count + 1
                WHERE api_usage.count < :quota
            RETURNING count
            ",
            [
                'clientId' => $clientId,
                'start' => $periodStart,
                'end' => $periodEnd,
                'quota' => $quota,
            ]
        );

        return $count !== false ? (int) $count : null;
    }
}
