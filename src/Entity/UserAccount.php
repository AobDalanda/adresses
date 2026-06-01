<?php

namespace App\Entity;

use App\Repository\UserAccountRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserAccountRepository::class)]
#[ORM\Table(name: 'user_account')]
class UserAccount
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(length: 20, unique: true)]
    private string $phone;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(length: 180, nullable: true, unique: true)]
    private ?string $email = null;

    #[ORM\Column]
    private bool $verified = false;

    #[ORM\Column(length: 20, options: ['default' => 'client'])]
    private string $accountType = 'client';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $profilePhotoPath = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $identityDocumentPath = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $identityDocumentNumber = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $driverLicensePath = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, UserSubscription> */
    #[ORM\OneToMany(mappedBy: 'user', targetEntity: UserSubscription::class)]
    private Collection $subscriptions;

    /** @var Collection<int, UsageCounter> */
    #[ORM\OneToMany(mappedBy: 'user', targetEntity: UsageCounter::class)]
    private Collection $usageCounters;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->subscriptions = new ArrayCollection();
        $this->usageCounters = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPhone(): string
    {
        return $this->phone;
    }

    public function setPhone(string $phone): self
    {
        $this->phone = $phone;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): self
    {
        $this->email = $email;

        return $this;
    }

    public function isVerified(): bool
    {
        return $this->verified;
    }

    public function setVerified(bool $verified): self
    {
        $this->verified = $verified;

        return $this;
    }

    public function getAccountType(): string
    {
        return $this->accountType;
    }

    public function setAccountType(string $accountType): self
    {
        $this->accountType = $accountType;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getIdentityDocumentNumber(): ?string
    {
        return $this->identityDocumentNumber;
    }

    public function setIdentityDocumentNumber(?string $identityDocumentNumber): self
    {
        $this->identityDocumentNumber = $identityDocumentNumber;

        return $this;
    }
}
