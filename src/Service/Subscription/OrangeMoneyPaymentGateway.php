<?php

namespace App\Service\Subscription;

use App\Enum\PaymentProvider;

final class OrangeMoneyPaymentGateway extends AbstractMobileMoneyPaymentGateway
{
    protected function provider(): PaymentProvider
    {
        return PaymentProvider::ORANGE_MONEY;
    }
}
