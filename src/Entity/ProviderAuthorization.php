<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ProviderAuthorizationStatus;
use App\Repository\ProviderAuthorizationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProviderAuthorizationRepository::class)]
#[ORM\Table(name: 'provider_authorization')]
class ProviderAuthorization
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: ProviderProfile::class)]
    #[ORM\JoinColumn(name: 'provider_profile_id', nullable: false, unique: true, onDelete: 'CASCADE')]
    private ProviderProfile $providerProfile;

    #[ORM\ManyToOne(targetEntity: ProviderApplication::class)]
    #[ORM\JoinColumn(name: 'source_application_id', nullable: true, onDelete: 'SET NULL')]
    private ?ProviderApplication $sourceApplication = null;

    #[ORM\ManyToOne(targetEntity: ProviderApplicationRevision::class)]
    #[ORM\JoinColumn(name: 'source_revision_id', nullable: true, onDelete: 'SET NULL')]
    private ?ProviderApplicationRevision $sourceRevision = null;

    #[ORM\Column(length: 20, enumType: ProviderAuthorizationStatus::class)]
    private ProviderAuthorizationStatus $status = ProviderAuthorizationStatus::INACTIVE;

    #[ORM\Column]
    private bool $canDeliver = false;

    #[ORM\Column]
    private bool $canTransportPeople = false;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $suspensionReasonCode = null;

    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $suspendedAt = null;

    #[ORM\ManyToOne(targetEntity: UserAccount::class)]
    #[ORM\JoinColumn(name: 'suspended_by', nullable: true, onDelete: 'SET NULL')]
    private ?UserAccount $suspendedBy = null;

    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $reactivatedAt = null;

    #[ORM\ManyToOne(targetEntity: UserAccount::class)]
    #[ORM\JoinColumn(name: 'reactivated_by', nullable: true, onDelete: 'SET NULL')]
    private ?UserAccount $reactivatedBy = null;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    private int $lockVersion = 1;

    public function __construct(ProviderProfile $providerProfile)
    {
        $this->providerProfile = $providerProfile;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProviderProfile(): ProviderProfile
    {
        return $this->providerProfile;
    }

    public function getSourceApplication(): ?ProviderApplication
    {
        return $this->sourceApplication;
    }

    public function getSourceRevision(): ?ProviderApplicationRevision
    {
        return $this->sourceRevision;
    }

    public function getStatus(): ProviderAuthorizationStatus
    {
        return $this->status;
    }

    public function canDeliver(): bool
    {
        return $this->canDeliver;
    }

    public function canTransportPeople(): bool
    {
        return $this->canTransportPeople;
    }

    public function getSuspensionReasonCode(): ?string
    {
        return $this->suspensionReasonCode;
    }

    public function getSuspendedAt(): ?\DateTimeImmutable
    {
        return $this->suspendedAt;
    }

    public function getSuspendedBy(): ?UserAccount
    {
        return $this->suspendedBy;
    }

    public function getReactivatedAt(): ?\DateTimeImmutable
    {
        return $this->reactivatedAt;
    }

    public function getReactivatedBy(): ?UserAccount
    {
        return $this->reactivatedBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getLockVersion(): int
    {
        return $this->lockVersion;
    }
}
