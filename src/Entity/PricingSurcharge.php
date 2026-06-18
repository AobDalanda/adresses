<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'pricing_surcharges')]
class PricingSurcharge
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PricingModel::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private PricingModel $pricingModel;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(length: 20)]
    private string $type;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $value = '0.00';

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $conditionJson = [];

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }
}
