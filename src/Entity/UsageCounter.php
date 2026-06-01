<?php

namespace App\Entity;

use App\Repository\UsageCounterRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UsageCounterRepository::class)]
#[ORM\Table(name: 'usage_counter')]
#[ORM\UniqueConstraint(name: 'uniq_usage_counter_period', columns: ['user_id', 'period_start', 'period_end'])]
#[ORM\HasLifecycleCallbacks]
class UsageCounter
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: UserAccount::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private UserAccount $user;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $periodStart;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $periodEnd;

    #[ORM\Column]
    private int $addressesCreated = 0;

    #[ORM\Column]
    private int $qrCodesGenerated = 0;

    #[ORM\Column]
    private int $deliveriesCreated = 0;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->periodStart = $now;
        $this->periodEnd = $now;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    #[ORM\PrePersist]
    public function prePersist(): void
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    #[ORM\PreUpdate]
    public function preUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): UserAccount
    {
        return $this->user;
    }

    public function setUser(UserAccount $user): self
    {
        $this->user = $user;

        return $this;
    }

    public function getPeriodStart(): \DateTimeImmutable
    {
        return $this->periodStart;
    }

    public function setPeriodStart(\DateTimeImmutable $periodStart): self
    {
        $this->periodStart = $periodStart;

        return $this;
    }

    public function getPeriodEnd(): \DateTimeImmutable
    {
        return $this->periodEnd;
    }

    public function setPeriodEnd(\DateTimeImmutable $periodEnd): self
    {
        $this->periodEnd = $periodEnd;

        return $this;
    }

    public function getAddressesCreated(): int
    {
        return $this->addressesCreated;
    }

    public function incrementAddressesCreated(): self
    {
        ++$this->addressesCreated;

        return $this;
    }

    public function getQrCodesGenerated(): int
    {
        return $this->qrCodesGenerated;
    }

    public function incrementQrCodesGenerated(): self
    {
        ++$this->qrCodesGenerated;

        return $this;
    }

    public function getDeliveriesCreated(): int
    {
        return $this->deliveriesCreated;
    }

    public function incrementDeliveriesCreated(): self
    {
        ++$this->deliveriesCreated;

        return $this;
    }
}
