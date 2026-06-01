<?php

namespace App\Service\Subscription;

use App\Entity\PaymentTransaction;
use App\Entity\UserAccount;
use App\Enum\PaymentProvider;
use App\Enum\PaymentTransactionStatus;
use App\Enum\SubscriptionEventType;
use App\Exception\PaymentAlreadyProcessedException;
use App\Exception\PaymentAmountMismatchException;
use App\Exception\PaymentCurrencyMismatchException;
use App\Exception\PaymentTransactionNotFoundException;
use App\Repository\PaymentTransactionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

final class PaymentManager
{
    /**
     * @param iterable<PaymentGatewayInterface> $paymentGateways
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SubscriptionManager $subscriptions,
        private readonly PaymentTransactionRepository $transactions,
        private readonly SubscriptionEventLogger $eventLogger,
        private readonly NotificationManager $notifications,
        private readonly iterable $paymentGateways
    ) {
    }

    /**
     * @return array{subscriptionId: int|null, transactionId: int|null, providerReference: string, status: string, instructions: array<string, mixed>}
     */
    public function checkout(UserAccount $user, string $planCode, string $providerCode, ?string $phoneNumber = null): array
    {
        $provider = $this->parseProvider($providerCode);
        $plan = $this->subscriptions->getPlanByCode($planCode);
        $subscription = $this->subscriptions->createPendingSubscription($user, $plan, $provider);
        $gateway = $this->gatewayFor($provider);

        $gatewayResult = $gateway->initiate($plan->getPriceAmount(), $plan->getCurrency(), [
            'userId' => $user->getId(),
            'planCode' => $plan->getCode()->value,
            'phoneNumber' => $phoneNumber,
        ]);

        $transaction = (new PaymentTransaction())
            ->setUser($user)
            ->setSubscription($subscription)
            ->setProvider($provider)
            ->setProviderReference($gatewayResult['providerReference'])
            ->setAmount($plan->getPriceAmount())
            ->setCurrency($plan->getCurrency())
            ->setStatus(PaymentTransactionStatus::PENDING)
            ->setRawPayload($gatewayResult['rawPayload']);

        $this->entityManager->persist($transaction);
        $this->eventLogger->log($user, $subscription, SubscriptionEventType::PAYMENT_PENDING, null, PaymentTransactionStatus::PENDING->value, [
            'provider' => $provider->value,
            'providerReference' => $transaction->getProviderReference(),
        ]);

        $this->entityManager->flush();

        return [
            'subscriptionId' => $subscription->getId(),
            'transactionId' => $transaction->getId(),
            'providerReference' => $transaction->getProviderReference(),
            'status' => $transaction->getStatus()->value,
            'instructions' => $gatewayResult['instructions'],
        ];
    }

    public function processWebhook(PaymentProvider $provider, Request $request): PaymentTransaction
    {
        $gateway = $this->gatewayFor($provider);
        $payload = $gateway->parseWebhook($request);
        $transaction = $this->transactions->findOneByProviderReference($payload['providerReference']);

        if (!$transaction instanceof PaymentTransaction) {
            throw new PaymentTransactionNotFoundException('Transaction de paiement introuvable.');
        }

        if ($transaction->getStatus() === PaymentTransactionStatus::SUCCESS || $transaction->getStatus() === PaymentTransactionStatus::FAILED) {
            throw new PaymentAlreadyProcessedException('Le paiement a deja ete traite.');
        }

        $this->assertExpectedAmount($transaction, $payload['amount']);
        $this->assertExpectedCurrency($transaction, $payload['currency']);

        $normalizedStatus = $this->normalizeProviderStatus($payload['status']);
        $transaction
            ->setStatus($normalizedStatus)
            ->setRawPayload($payload['rawPayload']);

        if ($normalizedStatus === PaymentTransactionStatus::SUCCESS) {
            $transaction->setPaidAt(new \DateTimeImmutable());
            $this->subscriptions->activateSubscription($transaction->getSubscription(), $payload['providerSubscriptionId']);
            $this->eventLogger->log(
                $transaction->getUser(),
                $transaction->getSubscription(),
                SubscriptionEventType::PAYMENT_SUCCESS,
                PaymentTransactionStatus::PENDING->value,
                PaymentTransactionStatus::SUCCESS->value,
                ['providerReference' => $transaction->getProviderReference()]
            );
            $this->notifications->notify($transaction->getUser(), 'payment.success', [
                'providerReference' => $transaction->getProviderReference(),
            ]);
        } else {
            $this->eventLogger->log(
                $transaction->getUser(),
                $transaction->getSubscription(),
                SubscriptionEventType::PAYMENT_FAILED,
                PaymentTransactionStatus::PENDING->value,
                $normalizedStatus->value,
                ['providerReference' => $transaction->getProviderReference()]
            );
            $this->notifications->notify($transaction->getUser(), 'payment.failed', [
                'providerReference' => $transaction->getProviderReference(),
            ]);
        }

        $this->entityManager->flush();

        return $transaction;
    }

    private function parseProvider(string $providerCode): PaymentProvider
    {
        try {
            return PaymentProvider::from(strtolower($providerCode));
        } catch (\ValueError) {
            throw new \RuntimeException('INVALID_PAYMENT_PROVIDER');
        }
    }

    private function gatewayFor(PaymentProvider $provider): PaymentGatewayInterface
    {
        foreach ($this->paymentGateways as $gateway) {
            if ($gateway->supports($provider)) {
                return $gateway;
            }
        }

        throw new \RuntimeException('INVALID_PAYMENT_PROVIDER');
    }

    private function normalizeProviderStatus(string $status): PaymentTransactionStatus
    {
        return match (strtolower($status)) {
            'success', 'paid', 'completed', 'succeeded' => PaymentTransactionStatus::SUCCESS,
            'cancelled', 'canceled' => PaymentTransactionStatus::CANCELLED,
            'refunded' => PaymentTransactionStatus::REFUNDED,
            default => PaymentTransactionStatus::FAILED,
        };
    }

    private function assertExpectedAmount(PaymentTransaction $transaction, int $amount): void
    {
        if ($transaction->getAmount() !== $amount) {
            throw new PaymentAmountMismatchException('Montant de paiement invalide.');
        }
    }

    private function assertExpectedCurrency(PaymentTransaction $transaction, string $currency): void
    {
        if (strtoupper($transaction->getCurrency()) !== strtoupper($currency)) {
            throw new PaymentCurrencyMismatchException('Devise de paiement invalide.');
        }
    }
}
