<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ProviderApplicationStatus;
use App\Repository\ProviderDecisionHistoryRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ProviderDecisionHistoryRepository::class)]
#[ORM\Table(name: 'provider_decision_history')]
#[ORM\Index(name: 'idx_provider_decision_application_time', columns: ['application_id', 'occurred_at', 'id'])]
class ProviderDecisionHistory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ProviderApplication::class)]
    #[ORM\JoinColumn(name: 'application_id', nullable: false, onDelete: 'CASCADE')]
    private ProviderApplication $application;

    #[ORM\ManyToOne(targetEntity: ProviderApplicationRevision::class)]
    #[ORM\JoinColumn(name: 'revision_id', nullable: true, onDelete: 'SET NULL')]
    private ?ProviderApplicationRevision $revision;

    #[ORM\Column(length: 60)]
    private string $transition;

    #[ORM\Column(length: 32, nullable: true, enumType: ProviderApplicationStatus::class)]
    private ?ProviderApplicationStatus $oldStatus;

    #[ORM\Column(length: 32, nullable: true, enumType: ProviderApplicationStatus::class)]
    private ?ProviderApplicationStatus $newStatus;

    #[ORM\Column(length: 30)]
    private string $actorType;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $actorId;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $reasonCode;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $comment;

    /** @var list<string>|null */
    #[ORM\Column(type: 'json', nullable: true, options: ['jsonb' => true])]
    private ?array $affectedItems;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true, options: ['jsonb' => true])]
    private ?array $metadata;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(type: 'guid')]
    private string $correlationId;

    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $causationId;

    /**
     * @param list<string>|null $affectedItems
     * @param array<string, mixed>|null $metadata
     */
    public function __construct(
        ProviderApplication $application,
        ?ProviderApplicationRevision $revision,
        string $transition,
        ?ProviderApplicationStatus $oldStatus,
        ?ProviderApplicationStatus $newStatus,
        string $actorType,
        ?int $actorId = null,
        ?string $reasonCode = null,
        ?string $comment = null,
        ?array $affectedItems = null,
        ?array $metadata = null,
        ?string $correlationId = null,
        ?string $causationId = null,
    ) {
        $this->application = $application;
        $this->revision = $revision;
        $this->transition = $transition;
        $this->oldStatus = $oldStatus;
        $this->newStatus = $newStatus;
        $this->actorType = $actorType;
        $this->actorId = $actorId;
        $this->reasonCode = $reasonCode;
        $this->comment = $comment;
        $this->affectedItems = $affectedItems;
        $this->metadata = $metadata;
        $this->occurredAt = new \DateTimeImmutable();
        $this->correlationId = $correlationId ?? Uuid::v7()->toRfc4122();
        $this->causationId = $causationId;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getApplication(): ProviderApplication
    {
        return $this->application;
    }

    public function getRevision(): ?ProviderApplicationRevision
    {
        return $this->revision;
    }

    public function getTransition(): string
    {
        return $this->transition;
    }

    public function getOldStatus(): ?ProviderApplicationStatus
    {
        return $this->oldStatus;
    }

    public function getNewStatus(): ?ProviderApplicationStatus
    {
        return $this->newStatus;
    }

    public function getActorType(): string
    {
        return $this->actorType;
    }

    public function getActorId(): ?int
    {
        return $this->actorId;
    }

    public function getReasonCode(): ?string
    {
        return $this->reasonCode;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    /** @return list<string>|null */
    public function getAffectedItems(): ?array
    {
        return $this->affectedItems;
    }

    /** @return array<string, mixed>|null */
    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getCorrelationId(): string
    {
        return $this->correlationId;
    }

    public function getCausationId(): ?string
    {
        return $this->causationId;
    }
}
