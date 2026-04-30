<?php

namespace App\Service\Payment;

class MtnMoneyPaymentProvider implements PaymentProviderInterface
{
    public function __construct(
        private string $baseUrl,
        private string $apiKey
    ) {
    }

    public function getName(): string
    {
        return 'mtn_money';
    }

    public function initiatePayment(int $amountCents, string $currency, array $context): array
    {
        throw new \RuntimeException('MTN Mobile Money provider not configured yet.');
    }
}
