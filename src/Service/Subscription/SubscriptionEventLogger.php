<?php

namespace App\Service\Subscription;

use App\Entity\SubscriptionEvent;
use App\Entity\UserAccount;
use App\Entity\UserSubscription;
use App\Enum\SubscriptionEventType;
use Doctrine\ORM\EntityManagerInterface;

class SubscriptionEventLogger
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    public function log(
        UserAccount $user,
        ?UserSubscription $subscription,
        SubscriptionEventType $type,
        ?string $oldStatus = null,
        ?string $newStatus = null,
        ?array $metadata = null
    ): void {
        $event = (new SubscriptionEvent())
            ->setUser($user)
            ->setSubscription($subscription)
            ->setType($type)
            ->setOldStatus($oldStatus)
            ->setNewStatus($newStatus)
            ->setMetadata($metadata);

        $this->entityManager->persist($event);
    }
}
