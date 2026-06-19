<?php

namespace App\Enum;

enum DeliveryOrderStatus: string
{
    case DRAFT = 'DRAFT';
    case QUOTED = 'QUOTED';
    case CONFIRMED = 'CONFIRMED';
    case ASSIGNED = 'ASSIGNED';
    case PICKED_UP = 'PICKED_UP';
    case IN_TRANSIT = 'IN_TRANSIT';
    case DELIVERED = 'DELIVERED';
    case CANCELLED = 'CANCELLED';
    case FAILED = 'FAILED';
}
