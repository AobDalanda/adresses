<?php

namespace App\Service\Payment;

class StripePaymentProvider implements PaymentProviderInterface
{
    public function __construct(private string $apiKey)
    {
    }

    public function getName(): string
    {
        return 'stripe';
    }

    public function initiatePayment(int $amountCents, string $currency, array $context): array
    {
        throw new \RuntimeException('Stripe provider not configured yet.');
    }
}
