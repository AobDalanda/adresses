<?php

namespace App\Service\Payment;

interface PaymentProviderInterface
{
    public function getName(): string;

    /**
     * @param array<string, mixed> $context
     * @return array{providerRef: string|null, extra: array<string, mixed>}
     */
    public function initiatePayment(int $amountCents, string $currency, array $context): array;
}
