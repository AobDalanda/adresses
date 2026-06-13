<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ProviderAutomaticCheckStatus;
use App\Repository\ProviderAutomaticCheckRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProviderAutomaticCheckRepository::class)]
#[ORM\Table(name: 'provider_automatic_check')]
#[ORM\UniqueConstraint(
    name: 'uniq_provider_automatic_check_revision_type',
    columns: ['revision_id', 'check_type']
)]
class ProviderAutomaticCheck
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ProviderApplication::class)]
    #[ORM\JoinColumn(name: 'application_id', nullable: false, onDelete: 'CASCADE')]
    private ProviderApplication $application;

    #[ORM\ManyToOne(targetEntity: ProviderApplicationRevision::class)]
    #[ORM\JoinColumn(name: 'revision_id', nullable: false, onDelete: 'CASCADE')]
    private ProviderApplicationRevision $revision;

    #[ORM\Column(length: 60)]
    private string $checkType;

    #[ORM\Column(length: 20, enumType: ProviderAutomaticCheckStatus::class)]
    private ProviderAutomaticCheckStatus $status;

    #[ORM\Column(type: 'decimal', precision: 5, scale: 4, nullable: true)]
    private ?string $score;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json', options: ['jsonb' => true])]
    private array $details;

    #[ORM\Column(length: 40)]
    private string $engineVersion;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $completedAt;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $updatedAt;
}
