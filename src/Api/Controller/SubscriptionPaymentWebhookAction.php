<?php

namespace App\Api\Controller;

use App\Enum\PaymentProvider;
use App\Exception\InvalidWebhookSignatureException;
use App\Exception\PaymentAlreadyProcessedException;
use App\Exception\PaymentAmountMismatchException;
use App\Exception\PaymentCurrencyMismatchException;
use App\Exception\PaymentTransactionNotFoundException;
use App\Service\Subscription\PaymentManager;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class SubscriptionPaymentWebhookAction
{
    public function __construct(private readonly PaymentManager $payments)
    {
    }

    public function __invoke(string $provider, Request $request): JsonResponse
    {
        try {
            $enum = PaymentProvider::from(strtolower($provider));
            $transaction = $this->payments->processWebhook($enum, $request);
        } catch (\ValueError) {
            return new JsonResponse(['error' => ['code' => 'INVALID_PAYMENT_PROVIDER', 'message' => 'Provider invalide']], 400);
        } catch (InvalidWebhookSignatureException) {
            return new JsonResponse(['error' => ['code' => 'WEBHOOK_INVALID_SIGNATURE', 'message' => 'Signature webhook invalide']], 400);
        } catch (PaymentTransactionNotFoundException $e) {
            return new JsonResponse(['error' => ['code' => 'PAYMENT_FAILED', 'message' => $e->getMessage()]], 404);
        } catch (PaymentAlreadyProcessedException $e) {
            return new JsonResponse(['error' => ['code' => 'PAYMENT_ALREADY_PROCESSED', 'message' => $e->getMessage()]], 409);
        } catch (PaymentAmountMismatchException|PaymentCurrencyMismatchException $e) {
            return new JsonResponse(['error' => ['code' => 'PAYMENT_FAILED', 'message' => $e->getMessage()]], 400);
        }

        return new JsonResponse([
            'success' => true,
            'transactionId' => $transaction->getId(),
            'status' => $transaction->getStatus()->value,
        ]);
    }
}
