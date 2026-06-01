<?php

namespace App\Enum;

enum SubscriptionEventType: string
{
    case SUBSCRIPTION_CREATED = 'subscription.created';
    case SUBSCRIPTION_ACTIVATED = 'subscription.activated';
    case SUBSCRIPTION_EXPIRED = 'subscription.expired';
    case SUBSCRIPTION_CANCELLED = 'subscription.cancelled';
    case SUBSCRIPTION_RENEWED = 'subscription.renewed';
    case PAYMENT_PENDING = 'payment.pending';
    case PAYMENT_SUCCESS = 'payment.success';
    case PAYMENT_FAILED = 'payment.failed';
    case PLAN_CHANGED = 'plan.changed';
    case LIMIT_REACHED = 'limit.reached';
}
