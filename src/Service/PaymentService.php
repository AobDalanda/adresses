<?php

namespace App\Service;

use App\Enum\DeliveryPaymentStatus;
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
        $deliveryPayment = $this->extractDeliveryPaymentUpdate($payload);
        $ownerType = $deliveryPayment !== null ? 'DELIVERY_ORDER' : 'UNKNOWN';
        $ownerId = 0;

        if ($deliveryPayment !== null) {
            $ownerId = $deliveryPayment['deliveryOrderId'];
            $this->db->executeStatement(
                <<<'SQL'
                    UPDATE delivery_payment
                    SET status = :status,
                        payment_method = COALESCE(:paymentMethod, payment_method),
                        provider_reference = COALESCE(:providerReference, provider_reference),
                        paid_at = CASE WHEN :paidAt IS NOT NULL THEN CAST(:paidAt AS timestamptz) ELSE paid_at END,
                        updated_at = now()
                    WHERE delivery_order_id = :deliveryOrderId
                    SQL,
                [
                    'status' => $deliveryPayment['status'],
                    'paymentMethod' => $deliveryPayment['paymentMethod'],
                    'providerReference' => $providerRef,
                    'paidAt' => $deliveryPayment['paidAt'],
                    'deliveryOrderId' => $deliveryPayment['deliveryOrderId'],
                ]
            );
        }

        $this->db->executeStatement(
            "
            INSERT INTO payment_event
                (provider, provider_ref, status, amount_cents, currency, owner_type, owner_id, plan_id, payload)
            VALUES
                (:provider, :providerRef, :status, 0, 'GNF', :ownerType, :ownerId, NULL, :payload)
            ",
            [
                'provider' => $provider,
                'providerRef' => $providerRef,
                'status' => $status,
                'ownerType' => $ownerType,
                'ownerId' => $ownerId,
                'payload' => json_encode($payload),
            ]
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{deliveryOrderId: int, status: string, paymentMethod: ?string, paidAt: ?string}|null
     */
    private function extractDeliveryPaymentUpdate(array $payload): ?array
    {
        $deliveryPublicId = $payload['deliveryId'] ?? $payload['deliveryPublicId'] ?? null;
        $status = $payload['paymentStatus'] ?? $payload['status'] ?? null;
        if (!is_string($deliveryPublicId) || trim($deliveryPublicId) === '' || !is_string($status) || trim($status) === '') {
            return null;
        }

        $paymentStatus = DeliveryPaymentStatus::fromDatabase($status);
        if ($paymentStatus === null) {
            return null;
        }

        $deliveryOrderId = $this->db->fetchOne(
            'SELECT id FROM delivery_order WHERE public_id = :publicId LIMIT 1',
            ['publicId' => trim($deliveryPublicId)]
        );
        if ($deliveryOrderId === false) {
            return null;
        }

        $paidAt = $payload['paidAt'] ?? $payload['paid_at'] ?? null;
        $paymentMethod = $payload['paymentMethod'] ?? $payload['payment_method'] ?? null;

        return [
            'deliveryOrderId' => (int) $deliveryOrderId,
            'status' => $paymentStatus->toDatabase(),
            'paymentMethod' => is_string($paymentMethod) && trim($paymentMethod) !== '' ? trim($paymentMethod) : null,
            'paidAt' => is_string($paidAt) && trim($paidAt) !== '' ? trim($paidAt) : null,
        ];
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
