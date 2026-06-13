<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ProviderDocumentType;
use App\Repository\ProviderDocumentRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProviderDocumentRepository::class)]
#[ORM\Table(name: 'provider_document')]
#[ORM\Index(name: 'idx_provider_document_revision_type', columns: ['revision_id', 'document_type'])]
class ProviderDocument
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ProviderApplicationRevision::class)]
    #[ORM\JoinColumn(name: 'revision_id', nullable: false, onDelete: 'CASCADE')]
    private ProviderApplicationRevision $revision;

    #[ORM\Column(type: 'bigint', unique: true)]
    private int $assetId;

    #[ORM\Column(length: 40, enumType: ProviderDocumentType::class)]
    private ProviderDocumentType $documentType;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $side;

    #[ORM\Column(length: 64)]
    private string $checksumSha256;

    #[ORM\Column(length: 32)]
    private string $verificationStatus = 'VALID';

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        ProviderApplicationRevision $revision,
        int $assetId,
        ProviderDocumentType $documentType,
        string $checksumSha256,
        ?string $side = null,
    ) {
        $this->revision = $revision;
        $this->assetId = $assetId;
        $this->documentType = $documentType;
        $this->checksumSha256 = $checksumSha256;
        $this->side = $side;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRevision(): ProviderApplicationRevision
    {
        return $this->revision;
    }

    public function getAssetId(): int
    {
        return $this->assetId;
    }

    public function getDocumentType(): ProviderDocumentType
    {
        return $this->documentType;
    }

    public function getSide(): ?string
    {
        return $this->side;
    }

    public function getChecksumSha256(): string
    {
        return $this->checksumSha256;
    }

    public function getVerificationStatus(): string
    {
        return $this->verificationStatus;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
