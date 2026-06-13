<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ProviderActivity;
use App\Repository\ProviderApplicationRevisionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProviderApplicationRevisionRepository::class)]
#[ORM\Table(name: 'provider_application_revision')]
#[ORM\UniqueConstraint(name: 'uniq_provider_application_revision_version', columns: ['application_id', 'version'])]
class ProviderApplicationRevision
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ProviderApplication::class)]
    #[ORM\JoinColumn(name: 'application_id', nullable: false, onDelete: 'CASCADE')]
    private ProviderApplication $application;

    #[ORM\Column]
    private int $version;

    /** @var list<string> */
    #[ORM\Column(type: 'json', options: ['jsonb' => true])]
    private array $activities;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json', options: ['jsonb' => true])]
    private array $profileData;

    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $submittedAt = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'supersedes_revision_id', nullable: true, onDelete: 'SET NULL')]
    private ?self $supersedesRevision = null;

    #[ORM\ManyToOne(targetEntity: UserAccount::class)]
    #[ORM\JoinColumn(name: 'created_by', nullable: false, onDelete: 'RESTRICT')]
    private UserAccount $createdBy;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    /**
     * @param non-empty-list<ProviderActivity> $activities
     * @param array<string, mixed> $profileData
     */
    public function __construct(
        ProviderApplication $application,
        int $version,
        array $activities,
        array $profileData,
        UserAccount $createdBy,
        ?self $supersedesRevision = null,
    ) {
        if ($version < 1) {
            throw new \InvalidArgumentException('A provider application revision version must be positive.');
        }

        if ($activities === []) {
            throw new \InvalidArgumentException('A provider application revision must contain at least one activity.');
        }

        $this->application = $application;
        $this->version = $version;
        $this->activities = array_map(
            static fn (ProviderActivity $activity): string => $activity->value,
            array_values($activities),
        );
        $this->profileData = $profileData;
        $this->createdBy = $createdBy;
        $this->supersedesRevision = $supersedesRevision;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getApplication(): ProviderApplication
    {
        return $this->application;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    /** @return list<ProviderActivity> */
    public function getActivities(): array
    {
        return array_map(
            static fn (string $activity): ProviderActivity => ProviderActivity::from($activity),
            $this->activities,
        );
    }

    /** @return array<string, mixed> */
    public function getProfileData(): array
    {
        return $this->profileData;
    }

    public function getSubmittedAt(): ?\DateTimeImmutable
    {
        return $this->submittedAt;
    }

    public function getSupersedesRevision(): ?self
    {
        return $this->supersedesRevision;
    }

    public function getCreatedBy(): UserAccount
    {
        return $this->createdBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
