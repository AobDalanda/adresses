<?php

namespace App\Service\Subscription;

use App\Entity\UserAccount;

final class NotificationManager
{
    /**
     * @param array<string, mixed> $context
     */
    public function notify(UserAccount $user, string $type, array $context = []): void
    {
    }
}
