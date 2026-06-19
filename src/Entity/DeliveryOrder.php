<?php

namespace App\Entity;

use App\Enum\DeliveryOrderStatus;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'delivery_order')]
#[ORM\UniqueConstraint(name: 'uniq_delivery_order_public_id', columns: ['public_id'])]
#[ORM\Index(name: 'idx_delivery_order_customer_created_at', columns: ['customer_id', 'created_at', 'id'])]
#[ORM\Index(name: 'idx_delivery_order_status_created_at', columns: ['status', 'created_at', 'id'])]
#[ORM\HasLifecycleCallbacks]
class DeliveryOrder
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'public_id', length: 36, unique: true)]
    private string $publicId;

    #[ORM\ManyToOne(targetEntity: UserAccount::class)]
    #[ORM\JoinColumn(name: 'customer_id', nullable: false, onDelete: 'CASCADE')]
    private UserAccount $customer;

    #[ORM\ManyToOne(targetEntity: Address::class)]
    #[ORM\JoinColumn(name: 'pickup_address_id', nullable: false, onDelete: 'RESTRICT')]
    private Address $pickupAddress;

    #[ORM\ManyToOne(targetEntity: Address::class)]
    #[ORM\JoinColumn(name: 'dropoff_address_id', nullable: false, onDelete: 'RESTRICT')]
    private Address $dropoffAddress;

    #[ORM\Column(name: 'service_type_code', length: 40)]
    private string $serviceTypeCode;

    #[ORM\Column(name: 'vehicle_type_code', length: 40)]
    private string $vehicleTypeCode;

    #[ORM\Column(length: 20, enumType: DeliveryOrderStatus::class)]
    private DeliveryOrderStatus $status = DeliveryOrderStatus::DRAFT;

    #[ORM\Column(name: 'scheduled_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $scheduledAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(name: 'recipient_name', length: 120, nullable: true)]
    private ?string $recipientName = null;

    #[ORM\Column(name: 'recipient_phone', length: 30, nullable: true)]
    private ?string $recipientPhone = null;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'confirmed_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $confirmedAt = null;

    #[ORM\Column(name: 'cancelled_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $cancelledAt = null;

    #[ORM\Column(name: 'completed_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->publicId = Uuid::v7()->toRfc4122();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    #[ORM\PrePersist]
    public function prePersist(): void
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    #[ORM\PreUpdate]
    public function preUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }
}
