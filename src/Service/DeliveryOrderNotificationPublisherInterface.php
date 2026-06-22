<?php

declare(strict_types=1);

namespace App\Service;

interface DeliveryOrderNotificationPublisherInterface
{
    public const NEW_DELIVERY_ORDER_TOPIC = 'drivers/delivery-orders/new';

    /**
     * @param array<string, mixed> $delivery
     */
    public function publishNewDeliveryOrder(array $delivery): bool;
}
