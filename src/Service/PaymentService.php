<?php

namespace App\Service;

use App\Service\Payment\PaymentProviderInterface;
use Doctrine\DBAL\Connection;

class PaymentService
{
    /**
     * @param iterable<PaymentProviderInterface> $providers
     */
    public function __construct(
        private Connection $db,
        private iterable $providers
    ) {
    }

    /**
     * @return array{paymentEventId: int, provider: string, amountCents: int, currency: string, status: string}
     */
    public function initiateSubscriptionPayment(string $provider, string $ownerType, int $ownerId, string $planCode): array
    {
        $plan = $this->db->fetchAssociative(
            "
            SELECT id, price_cents, currency
            FROM subscription_plan
            WHERE code = :code
            LIMIT 1
            ",
            ['code' => $planCode]
        );

        if (!$plan) {
            throw new \RuntimeException('Plan not found.');
        }

        $providerInstance = $this->getProvider($provider);
        if (!$providerInstance) {
            throw new \RuntimeException('Payment provider not supported.');
        }

        $providerResult = $providerInstance->initiatePayment(
            (int) $plan['price_cents'],
            (string) $plan['currency'],
            [
                'ownerType' => $ownerType,
                'ownerId' => $ownerId,
                'planCode' => $planCode,
            ]
        );

        $eventId = (int) $this->db->fetchOne(
            "
            INSERT INTO payment_event
                (provider, provider_ref, status, amount_cents, currency, owner_type, owner_id, plan_id, payload)
            VALUES
                (:provider, :providerRef, 'PENDING', :amount, :currency, :ownerType, :ownerId, :planId, :payload)
            RETURNING id
            ",
            [
                'provider' => $provider,
                'providerRef' => $providerResult['providerRef'],
                'amount' => $plan['price_cents'],
                'currency' => $plan['currency'],
                'ownerType' => $ownerType,
                'ownerId' => $ownerId,
                'planId' => $plan['id'],
                'payload' => json_encode($providerResult['extra']),
            ]
        );

        return [
            'paymentEventId' => $eventId,
            'provider' => $provider,
            'amountCents' => (int) $plan['price_cents'],
            'currency' => (string) $plan['currency'],
            'status' => 'PENDING',
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function recordWebhookEvent(string $provider, ?string $providerRef, array $payload, string $status): void
    {
        $this->db->executeStatement(
            "
            INSERT INTO payment_event
                (provider, provider_ref, status, amount_cents, currency, owner_type, owner_id, plan_id, payload)
            VALUES
                (:provider, :providerRef, :status, 0, 'GNF', 'UNKNOWN', 0, NULL, :payload)
            ",
            [
                'provider' => $provider,
                'providerRef' => $providerRef,
                'status' => $status,
                'payload' => json_encode($payload),
            ]
        );
    }

    private function getProvider(string $provider): ?PaymentProviderInterface
    {
        foreach ($this->providers as $candidate) {
            if ($candidate->getName() === $provider) {
                return $candidate;
            }
        }

        return null;
    }
}
