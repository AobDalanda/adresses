<?php

namespace App\Entity;

use App\Enum\PaymentProvider;
use App\Enum\PaymentTransactionStatus;
use App\Repository\PaymentTransactionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PaymentTransactionRepository::class)]
#[ORM\Table(name: 'payment_transaction')]
#[ORM\HasLifecycleCallbacks]
class PaymentTransaction
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: UserAccount::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private UserAccount $user;

    #[ORM\ManyToOne(targetEntity: UserSubscription::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private UserSubscription $subscription;

    #[ORM\Column(enumType: PaymentProvider::class)]
    private PaymentProvider $provider;

    #[ORM\Column(length: 120, unique: true)]
    private string $providerReference;

    #[ORM\Column]
    private int $amount;

    #[ORM\Column(length: 5)]
    private string $currency = 'GNF';

    #[ORM\Column(enumType: PaymentTransactionStatus::class)]
    private PaymentTransactionStatus $status = PaymentTransactionStatus::PENDING;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $rawPayload = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $paidAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $now = new \DateTimeImmutable();
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

    public function getSubscription(): UserSubscription
    {
        return $this->subscription;
    }

    public function setSubscription(UserSubscription $subscription): self
    {
        $this->subscription = $subscription;

        return $this;
    }

    public function getProvider(): PaymentProvider
    {
        return $this->provider;
    }

    public function setProvider(PaymentProvider $provider): self
    {
        $this->provider = $provider;

        return $this;
    }

    public function getProviderReference(): string
    {
        return $this->providerReference;
    }

    public function setProviderReference(string $providerReference): self
    {
        $this->providerReference = $providerReference;

        return $this;
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function setAmount(int $amount): self
    {
        $this->amount = $amount;

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

    public function getStatus(): PaymentTransactionStatus
    {
        return $this->status;
    }

    public function setStatus(PaymentTransactionStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getRawPayload(): ?array
    {
        return $this->rawPayload;
    }

    public function setRawPayload(?array $rawPayload): self
    {
        $this->rawPayload = $rawPayload;

        return $this;
    }

    public function getPaidAt(): ?\DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function setPaidAt(?\DateTimeImmutable $paidAt): self
    {
        $this->paidAt = $paidAt;

        return $this;
    }
}
