<?php

namespace App\Entity;

use App\Enum\SubscriptionEventType;
use App\Repository\SubscriptionEventRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SubscriptionEventRepository::class)]
#[ORM\Table(name: 'subscription_event')]
class SubscriptionEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: UserAccount::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private UserAccount $user;

    #[ORM\ManyToOne(targetEntity: UserSubscription::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?UserSubscription $subscription = null;

    #[ORM\Column(enumType: SubscriptionEventType::class)]
    private SubscriptionEventType $type;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $oldStatus = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $newStatus = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $metadata = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setUser(UserAccount $user): self
    {
        $this->user = $user;

        return $this;
    }

    public function setSubscription(?UserSubscription $subscription): self
    {
        $this->subscription = $subscription;

        return $this;
    }

    public function setType(SubscriptionEventType $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function setOldStatus(?string $oldStatus): self
    {
        $this->oldStatus = $oldStatus;

        return $this;
    }

    public function setNewStatus(?string $newStatus): self
    {
        $this->newStatus = $newStatus;

        return $this;
    }

    public function setMetadata(?array $metadata): self
    {
        $this->metadata = $metadata;

        return $this;
    }
}
