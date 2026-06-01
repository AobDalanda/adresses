<?php

namespace App\Entity;

use App\Enum\SubscriptionPlanCode;
use App\Repository\SubscriptionPlanRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SubscriptionPlanRepository::class)]
#[ORM\Table(name: 'saas_subscription_plan')]
#[ORM\HasLifecycleCallbacks]
class SubscriptionPlan
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(enumType: SubscriptionPlanCode::class, unique: true)]
    private SubscriptionPlanCode $code;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column]
    private int $priceAmount = 0;

    #[ORM\Column(length: 5)]
    private string $currency = 'GNF';

    #[ORM\Column]
    private int $durationDays = 30;

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column(nullable: true)]
    private ?int $maxAddresses = null;

    #[ORM\Column(nullable: true)]
    private ?int $maxQrCodes = null;

    #[ORM\Column(nullable: true)]
    private ?int $maxDeliveriesPerMonth = null;

    #[ORM\Column]
    private bool $canTrackDelivery = false;

    #[ORM\Column]
    private bool $canUseCustomQrCode = false;

    #[ORM\Column]
    private bool $canCreateBusinessAddress = false;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->code = SubscriptionPlanCode::FREE;
        $this->name = 'Free';
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

    public function getCode(): SubscriptionPlanCode
    {
        return $this->code;
    }

    public function setCode(SubscriptionPlanCode $code): self
    {
        $this->code = $code;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getPriceAmount(): int
    {
        return $this->priceAmount;
    }

    public function setPriceAmount(int $priceAmount): self
    {
        $this->priceAmount = $priceAmount;

        return $this;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): self
    {
        $this->currency = $currency;

        return $this;
    }

    public function getDurationDays(): int
    {
        return $this->durationDays;
    }

    public function setDurationDays(int $durationDays): self
    {
        $this->durationDays = $durationDays;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function getMaxAddresses(): ?int
    {
        return $this->maxAddresses;
    }

    public function setMaxAddresses(?int $maxAddresses): self
    {
        $this->maxAddresses = $maxAddresses;

        return $this;
    }

    public function getMaxQrCodes(): ?int
    {
        return $this->maxQrCodes;
    }

    public function setMaxQrCodes(?int $maxQrCodes): self
    {
        $this->maxQrCodes = $maxQrCodes;

        return $this;
    }

    public function getMaxDeliveriesPerMonth(): ?int
    {
        return $this->maxDeliveriesPerMonth;
    }

    public function setMaxDeliveriesPerMonth(?int $maxDeliveriesPerMonth): self
    {
        $this->maxDeliveriesPerMonth = $maxDeliveriesPerMonth;

        return $this;
    }

    public function canTrackDelivery(): bool
    {
        return $this->canTrackDelivery;
    }

    public function setCanTrackDelivery(bool $canTrackDelivery): self
    {
        $this->canTrackDelivery = $canTrackDelivery;

        return $this;
    }

    public function canUseCustomQrCode(): bool
    {
        return $this->canUseCustomQrCode;
    }

    public function setCanUseCustomQrCode(bool $canUseCustomQrCode): self
    {
        $this->canUseCustomQrCode = $canUseCustomQrCode;

        return $this;
    }

    public function canCreateBusinessAddress(): bool
    {
        return $this->canCreateBusinessAddress;
    }

    public function setCanCreateBusinessAddress(bool $canCreateBusinessAddress): self
    {
        $this->canCreateBusinessAddress = $canCreateBusinessAddress;

        return $this;
    }
}
