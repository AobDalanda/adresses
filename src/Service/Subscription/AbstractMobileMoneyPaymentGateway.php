<?php

namespace App\Service\Subscription;

use App\Enum\PaymentProvider;
use App\Exception\InvalidWebhookSignatureException;
use Symfony\Component\HttpFoundation\Request;

abstract class AbstractMobileMoneyPaymentGateway implements PaymentGatewayInterface
{
    public function __construct(
        ?string $signingSecret = null
    ) {
        $this->signingSecret = $signingSecret ?? '';
    }

    private readonly string $signingSecret;

    abstract protected function provider(): PaymentProvider;

    public function supports(PaymentProvider $provider): bool
    {
        return $provider === $this->provider();
    }

    public function initiate(int $amount, string $currency, array $context): array
    {
        $phoneNumber = isset($context['phoneNumber']) && is_string($context['phoneNumber']) ? $context['phoneNumber'] : null;
        $reference = sprintf('%s_%s', $this->provider()->value, bin2hex(random_bytes(8)));

        return [
            'providerReference' => $reference,
            'status' => 'pending',
            'instructions' => [
                'provider' => $this->provider()->value,
                'phoneNumber' => $phoneNumber,
                'amount' => $amount,
                'currency' => $currency,
                'reference' => $reference,
                'message' => 'Validez le paiement sur votre téléphone pour finaliser votre abonnement.',
            ],
            'rawPayload' => [
                'reference' => $reference,
                'phoneNumber' => $phoneNumber,
                'amount' => $amount,
                'currency' => $currency,
            ],
        ];
    }

    public function parseWebhook(Request $request): array
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            throw new \InvalidArgumentException('Invalid JSON body');
        }

        $signature = $request->headers->get('X-Signature');
        if ($this->signingSecret !== '') {
            $expected = hash_hmac('sha256', $request->getContent(), $this->signingSecret);
            if (!is_string($signature) || !hash_equals($expected, $signature)) {
                throw new InvalidWebhookSignatureException('Signature invalide');
            }
        }

        $reference = $payload['reference'] ?? null;
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
