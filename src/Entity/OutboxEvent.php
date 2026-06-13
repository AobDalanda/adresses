<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OutboxEventRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: OutboxEventRepository::class)]
#[ORM\Table(name: 'outbox_event')]
#[ORM\Index(name: 'idx_outbox_event_unpublished', columns: ['published_at', 'occurred_at'])]
class OutboxEvent
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(length: 80)]
    private string $aggregateType;

    #[ORM\Column(length: 64)]
    private string $aggregateId;

    #[ORM\Column(length: 120)]
    private string $eventName;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json', options: ['jsonb' => true])]
    private array $payload;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column]
    private int $attempts = 0;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $nextAttemptAt = null;

    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $failedAt = null;

    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $processingAt = null;

    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $processingToken = null;

    /** @param array<string, mixed> $payload */
    public function __construct(
        string $aggregateType,
        string $aggregateId,
        string $eventName,
        array $payload,
        ?string $id = null,
    ) {
        $this->id = $id ?? Uuid::v7()->toRfc4122();
        $this->aggregateType = $aggregateType;
        $this->aggregateId = $aggregateId;
        $this->eventName = $eventName;
        $this->payload = $payload;
        $this->occurredAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getAggregateType(): string
    {
        return $this->aggregateType;
    }

    public function getAggregateId(): string
    {
        return $this->aggregateId;
    }

    public function getEventName(): string
    {
        return $this->eventName;
    }

    /** @return array<string, mixed> */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function getNextAttemptAt(): ?\DateTimeImmutable
    {
        return $this->nextAttemptAt;
    }

    public function getFailedAt(): ?\DateTimeImmutable
    {
        return $this->failedAt;
    }

    public function getProcessingAt(): ?\DateTimeImmutable
    {
        return $this->processingAt;
    }

    public function getProcessingToken(): ?string
    {
        return $this->processingToken;
    }
}
