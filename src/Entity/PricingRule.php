<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'pricing_rules')]
class PricingRule
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PricingModel::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private PricingModel $pricingModel;

    #[ORM\ManyToOne(targetEntity: ServiceType::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?ServiceType $serviceType = null;

    #[ORM\ManyToOne(targetEntity: VehicleType::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?VehicleType $vehicleType = null;

    #[ORM\ManyToOne(targetEntity: CustomerType::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?CustomerType $customerType = null;

    #[ORM\ManyToOne(targetEntity: Zone::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?Zone $zone = null;

    #[ORM\Column(length: 64, unique: true)]
    private string $code;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $distanceMin = '0.00';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $distanceMax = null;

    #[ORM\Column]
    private int $basePrice = 0;

    #[ORM\Column]
    private int $pricePerKm = 0;

    #[ORM\Column(length: 3)]
    private string $currency = 'GNF';

    #[ORM\Column]
    private int $priority = 0;

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

    public function getPricingModel(): PricingModel
    {
        return $this->pricingModel;
    }

    public function setPricingModel(PricingModel $pricingModel): self
    {
        $this->pricingModel = $pricingModel;

        return $this;
    }

    public function getServiceType(): ?ServiceType
    {
        return $this->serviceType;
    }

    public function setServiceType(?ServiceType $serviceType): self
    {
        $this->serviceType = $serviceType;

        return $this;
    }

    public function getVehicleType(): ?VehicleType
    {
        return $this->vehicleType;
    }

    public function setVehicleType(?VehicleType $vehicleType): self
    {
        $this->vehicleType = $vehicleType;

        return $this;
    }

    public function getZone(): ?Zone
    {
        return $this->zone;
    }

    public function setZone(?Zone $zone): self
    {
        $this->zone = $zone;

        return $this;
    }

    public function getCustomerType(): ?CustomerType
    {
        return $this->customerType;
    }

    public function setCustomerType(?CustomerType $customerType): self
    {
        $this->customerType = $customerType;

        return $this;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): self
    {
        $this->code = strtoupper(trim($code));

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = trim($name);

        return $this;
    }

    public function getDistanceMin(): string
    {
        return $this->distanceMin;
    }

    public function setDistanceMin(string $distanceMin): self
    {
        $this->distanceMin = $distanceMin;

        return $this;
    }

    public function getDistanceMax(): ?string
    {
        return $this->distanceMax;
    }

    public function setDistanceMax(?string $distanceMax): self
    {
        $this->distanceMax = $distanceMax;

        return $this;
    }

    public function getBasePrice(): int
    {
        return $this->basePrice;
    }

    public function setBasePrice(int $basePrice): self
    {
        $this->basePrice = $basePrice;

        return $this;
    }

    public function getPricePerKm(): int
    {
        return $this->pricePerKm;
    }

    public function setPricePerKm(int $pricePerKm): self
    {
        $this->pricePerKm = $pricePerKm;

        return $this;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): self
    {
        $this->currency = strtoupper(trim($currency));

        return $this;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function setPriority(int $priority): self
    {
        $this->priority = $priority;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
