<?php

namespace App\Service\Payment;

class OrangeMoneyPaymentProvider implements PaymentProviderInterface
{
    public function __construct(
        private string $baseUrl,
        private string $apiKey
    ) {
    }

    public function getName(): string
    {
        return 'orange_money';
    }

    public function initiatePayment(int $amountCents, string $currency, array $context): array
    {
        throw new \RuntimeException('Orange Money provider not configured yet.');
    }
}
