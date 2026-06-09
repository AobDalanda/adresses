<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProviderProfileRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProviderProfileRepository::class)]
#[ORM\Table(name: 'provider_profile')]
#[ORM\Index(name: 'idx_provider_profile_validation_status', columns: ['validation_status'])]
class ProviderProfile
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_SUSPENDED = 'suspended';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'providerProfile')]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, unique: true, onDelete: 'CASCADE')]
    private UserAccount $user;

    #[ORM\Column]
    private bool $canDeliver = false;

    #[ORM\Column]
    private bool $canTransportPeople = false;

    #[ORM\Column(length: 20, options: ['default' => self::STATUS_PENDING])]
    private string $validationStatus = self::STATUS_PENDING;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(UserAccount $user, bool $canDeliver, bool $canTransportPeople)
    {
        $this->user = $user;
        $this->canDeliver = $canDeliver;
        $this->canTransportPeople = $canTransportPeople;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): UserAccount
    {
        return $this->user;
    }

    public function canDeliver(): bool
    {
        return $this->canDeliver;
    }

    public function canTransportPeople(): bool
    {
        return $this->canTransportPeople;
    }

    public function getValidationStatus(): string
    {
        return $this->validationStatus;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
