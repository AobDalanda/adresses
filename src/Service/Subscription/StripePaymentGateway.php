<?php

namespace App\Service\Subscription;

use App\Enum\PaymentProvider;
use Symfony\Component\HttpFoundation\Request;

final class StripePaymentGateway implements PaymentGatewayInterface
{
    public function __construct(?string $webhookSecret = null)
    {
        $this->webhookSecret = $webhookSecret ?? '';
    }

    private readonly string $webhookSecret;

    public function supports(PaymentProvider $provider): bool
    {
        return $provider === PaymentProvider::STRIPE;
    }

    public function initiate(int $amount, string $currency, array $context): array
    {
        $reference = sprintf('stripe_%s', bin2hex(random_bytes(8)));

        return [
            'providerReference' => $reference,
            'status' => 'pending',
            'instructions' => [
                'provider' => PaymentProvider::STRIPE->value,
                'checkoutSessionId' => $reference,
                'amount' => $amount,
                'currency' => $currency,
                'message' => 'Finalisez le paiement Stripe avec la session fournie.',
            ],
            'rawPayload' => [
                'reference' => $reference,
            ],
        ];
    }

    public function parseWebhook(Request $request): array
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            throw new \InvalidArgumentException('Invalid JSON body');
        }

        $reference = $payload['reference'] ?? $payload['id'] ?? null;
        $status = $payload['status'] ?? null;
        $amount = $payload['amount'] ?? null;
        $currency = $payload['currency'] ?? null;

        if (!is_string($reference) || $reference === '' || !is_string($status) || !is_numeric($amount) || !is_string($currency)) {
            throw new \InvalidArgumentException('Webhook payload invalide');
        }

        return [
            'providerReference' => $reference,
            'status' => strtolower($status),
            'amount' => (int) $amount,
            'currency' => strtoupper($currency),
            'rawPayload' => $payload,
            'providerSubscriptionId' => isset($payload['subscriptionId']) && is_string($payload['subscriptionId']) ? $payload['subscriptionId'] : null,
        ];
    }
}
