<?php

namespace App\Enum;

enum PaymentProvider: string
{
    case ORANGE_MONEY = 'orange_money';
    case MTN_MONEY = 'mtn_money';
    case STRIPE = 'stripe';
    case MANUAL_ADMIN = 'manual_admin';
}
