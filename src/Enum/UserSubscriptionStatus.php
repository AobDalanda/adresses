<?php

namespace App\Enum;

enum UserSubscriptionStatus: string
{
    case PENDING_PAYMENT = 'pending_payment';
    case ACTIVE = 'active';
    case TRIALING = 'trialing';
    case PAST_DUE = 'past_due';
    case CANCELLED = 'cancelled';
    case EXPIRED = 'expired';
}
