<?php

namespace App\Api\Controller;

use App\Service\PaymentService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class PaymentWebhookAction
{
    public function __construct(private PaymentService $payments)
    {
    }

    public function __invoke(string $provider, Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['message' => 'Invalid JSON body'], 400);
        }

        $providerRef = $payload['reference'] ?? null;

        $this->payments->recordWebhookEvent(
            $provider,
            is_string($providerRef) ? $providerRef : null,
            $payload,
            'RECEIVED'
        );

        return new JsonResponse(['message' => 'Webhook reçu'], 200);
    }
}
