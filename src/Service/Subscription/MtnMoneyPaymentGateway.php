<?php

namespace App\Service\Subscription;

use App\Enum\PaymentProvider;

final class MtnMoneyPaymentGateway extends AbstractMobileMoneyPaymentGateway
{
    protected function provider(): PaymentProvider
    {
        return PaymentProvider::MTN_MONEY;
    }
}
