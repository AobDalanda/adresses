<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'delivery_pricing_snapshot')]
#[ORM\UniqueConstraint(name: 'uniq_delivery_pricing_snapshot_order', columns: ['delivery_order_id'])]
class DeliveryPricingSnapshot
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: DeliveryOrder::class)]
    #[ORM\JoinColumn(name: 'delivery_order_id', nullable: false, onDelete: 'CASCADE')]
    private DeliveryOrder $deliveryOrder;

    #[ORM\Column(name: 'distance_km', type: 'decimal', precision: 8, scale: 2)]
    private string $distanceKm;

    #[ORM\Column(name: 'duration_minutes')]
    private int $durationMinutes;

    #[ORM\ManyToOne(targetEntity: Zone::class)]
    #[ORM\JoinColumn(name: 'zone_id', nullable: true, onDelete: 'SET NULL')]
    private ?Zone $zone = null;

    #[ORM\Column(name: 'customer_type_code', length: 40, nullable: true)]
    private ?string $customerTypeCode = null;

    #[ORM\Column(name: 'base_amount', type: 'decimal', precision: 12, scale: 2)]
    private string $baseAmount;

    #[ORM\Column(name: 'surcharge_amount', type: 'decimal', precision: 12, scale: 2)]
    private string $surchargeAmount = '0.00';

    #[ORM\Column(name: 'total_amount', type: 'decimal', precision: 12, scale: 2)]
    private string $totalAmount;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(name: 'pricing_payload', type: 'json')]
    private array $pricingPayload = [];

    #[ORM\Column(name: 'quoted_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $quotedAt;

    public function __construct()
    {
        $this->quotedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }
}
