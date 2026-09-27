<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\DeliveryOfferForbiddenException;
use Doctrine\DBAL\Connection;

/** Applies the canonical provider-account and document approval decision. */
final readonly class DriverAccessChecker
{
    public function __construct(private Connection $db)
    {
    }

    public function assertCanDeliver(int $driverId): void
    {
        $allowed = $this->db->fetchOne(
            <<<'SQL'
                SELECT 1
                FROM user_account account
                JOIN provider_profile profile ON profile.user_id = account.id
                JOIN provider_authorization authorization
                  ON authorization.provider_profile_id = profile.id
                WHERE account.id = :driverId
                  AND account.enabled = TRUE
                  AND profile.validation_status = 'approved'
                  AND profile.can_deliver = TRUE
                  AND authorization.status = 'ACTIVE'
                  AND authorization.can_deliver = TRUE
                LIMIT 1
                SQL,
            ['driverId' => $driverId],
        );

        if ($allowed === false) {
            throw new DeliveryOfferForbiddenException('DRIVER_NOT_AUTHORIZED');
        }
    }
}
