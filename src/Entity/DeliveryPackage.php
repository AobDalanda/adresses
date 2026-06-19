<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'delivery_package')]
#[ORM\UniqueConstraint(name: 'uniq_delivery_package_order', columns: ['delivery_order_id'])]
class DeliveryPackage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: DeliveryOrder::class)]
    #[ORM\JoinColumn(name: 'delivery_order_id', nullable: false, onDelete: 'CASCADE')]
    private DeliveryOrder $deliveryOrder;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'declared_value_amount', type: 'decimal', precision: 12, scale: 2, nullable: true)]
    private ?string $declaredValueAmount = null;

    #[ORM\Column(name: 'declared_value_currency', length: 3, nullable: true)]
    private ?string $declaredValueCurrency = null;

    #[ORM\Column(name: 'weight_kg', type: 'decimal', precision: 8, scale: 2, nullable: true)]
    private ?string $weightKg = null;

    #[ORM\Column(name: 'length_cm', type: 'decimal', precision: 8, scale: 2, nullable: true)]
    private ?string $lengthCm = null;

    #[ORM\Column(name: 'width_cm', type: 'decimal', precision: 8, scale: 2, nullable: true)]
    private ?string $widthCm = null;

    #[ORM\Column(name: 'height_cm', type: 'decimal', precision: 8, scale: 2, nullable: true)]
    private ?string $heightCm = null;

    #[ORM\Column]
    private bool $fragile = false;

    #[ORM\Column(name: 'photo_asset_id', type: 'bigint', nullable: true)]
    private ?int $photoAssetId = null;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
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
