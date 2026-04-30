<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

class SubscriptionService
{
    public function __construct(private Connection $db)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getActiveSubscription(string $ownerType, int $ownerId): ?array
    {
        return $this->db->fetchAssociative(
            "
            SELECT s.*, p.code AS plan_code, p.name AS plan_name, p.price_cents, p.currency, p.quota_create, p.quota_lookup
            FROM subscription s
            JOIN subscription_plan p ON s.plan_id = p.id
            WHERE s.owner_type = :ownerType
              AND s.owner_id = :ownerId
              AND s.status = 'ACTIVE'
              AND s.current_period_start <= now()
              AND s.current_period_end >= now()
            ORDER BY s.current_period_end DESC
            LIMIT 1
            ",
            [
                'ownerType' => $ownerType,
                'ownerId' => $ownerId,
            ]
        ) ?: null;
    }
}
