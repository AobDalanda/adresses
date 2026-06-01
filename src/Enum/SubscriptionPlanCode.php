<?php

namespace App\Enum;

enum SubscriptionPlanCode: string
{
    case FREE = 'FREE';
    case BASIC = 'BASIC';
    case PREMIUM = 'PREMIUM';
    case BUSINESS = 'BUSINESS';
}
