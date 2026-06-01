<?php

namespace App\Service\Subscription;

use App\Dto\Subscription\ManualPaymentWebhookInput;
use App\Enum\PaymentProvider;
use Symfony\Component\HttpFoundation\Request;

interface PaymentGatewayInterface
{
    public function supports(PaymentProvider $provider): bool;

    /**
     * @param array<string, mixed> $context
     * @return array{providerReference: string, status: string, instructions: array<string, mixed>, rawPayload: array<string, mixed>}
     */
    public function initiate(int $amount, string $currency, array $context): array;

    /**
     * @return array{providerReference: string, status: string, amount: int, currency: string, rawPayload: array<string, mixed>, providerSubscriptionId: ?string}
     */
    public function parseWebhook(Request $request): array;
}
