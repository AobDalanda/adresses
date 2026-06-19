<?php

namespace App\Entity;

use App\Enum\DeliveryOrderStatus;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'delivery_status_history')]
#[ORM\Index(name: 'idx_delivery_status_history_order_created_at', columns: ['delivery_order_id', 'created_at', 'id'])]
class DeliveryStatusHistory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: DeliveryOrder::class)]
    #[ORM\JoinColumn(name: 'delivery_order_id', nullable: false, onDelete: 'CASCADE')]
    private DeliveryOrder $deliveryOrder;

    #[ORM\Column(length: 20, enumType: DeliveryOrderStatus::class)]
    private DeliveryOrderStatus $status;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $comment = null;

    #[ORM\ManyToOne(targetEntity: UserAccount::class)]
    #[ORM\JoinColumn(name: 'changed_by_user_id', nullable: true, onDelete: 'SET NULL')]
    private ?UserAccount $changedByUser = null;

    #[ORM\Column(name: 'changed_by_role', length: 30, nullable: true)]
    private ?string $changedByRole = null;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(DeliveryOrderStatus $status)
    {
        $this->status = $status;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }
}
