<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ProviderApplicationStatus;
use App\Repository\ProviderApplicationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ProviderApplicationRepository::class)]
#[ORM\Table(name: 'provider_application')]
#[ORM\Index(name: 'idx_provider_application_profile_status', columns: ['provider_profile_id', 'status'])]
#[ORM\Index(name: 'idx_provider_application_status_updated', columns: ['status', 'updated_at', 'id'])]
class ProviderApplication
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(type: 'guid', unique: true)]
    private string $publicId;

    #[ORM\ManyToOne(targetEntity: ProviderProfile::class)]
    #[ORM\JoinColumn(name: 'provider_profile_id', nullable: false, onDelete: 'CASCADE')]
    private ProviderProfile $providerProfile;

    #[ORM\Column(length: 32, enumType: ProviderApplicationStatus::class)]
    private ProviderApplicationStatus $status = ProviderApplicationStatus::DRAFT;

    #[ORM\ManyToOne(targetEntity: ProviderApplicationRevision::class)]
    #[ORM\JoinColumn(name: 'current_revision_id', nullable: true, onDelete: 'SET NULL')]
    private ?ProviderApplicationRevision $currentRevision = null;

    #[ORM\ManyToOne(targetEntity: ProviderApplicationRevision::class)]
    #[ORM\JoinColumn(name: 'approved_revision_id', nullable: true, onDelete: 'SET NULL')]
    private ?ProviderApplicationRevision $approvedRevision = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $legacyDriverApplicationId = null;

    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $submittedAt = null;

    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    private int $lockVersion = 1;

    public function __construct(ProviderProfile $providerProfile, ?string $publicId = null)
    {
        $this->providerProfile = $providerProfile;
        $this->publicId = $publicId ?? Uuid::v7()->toRfc4122();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPublicId(): string
    {
        return $this->publicId;
    }

    public function getProviderProfile(): ProviderProfile
    {
        return $this->providerProfile;
    }

    public function getStatus(): ProviderApplicationStatus
    {
        return $this->status;
    }

    public function getCurrentRevision(): ?ProviderApplicationRevision
    {
        return $this->currentRevision;
    }

    public function getApprovedRevision(): ?ProviderApplicationRevision
    {
        return $this->approvedRevision;
    }

    public function getLegacyDriverApplicationId(): ?int
    {
        return $this->legacyDriverApplicationId;
    }

    public function getSubmittedAt(): ?\DateTimeImmutable
    {
        return $this->submittedAt;
    }

    public function getDecidedAt(): ?\DateTimeImmutable
    {
        return $this->decidedAt;
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
