<?php

namespace App\Controller;

use App\Service\PaymentService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/payment')]
class PaymentWebhookController extends AbstractController
{
    public function __construct(private PaymentService $payments)
    {
    }

    #[Route('/webhook/{provider}', methods: ['POST'])]
    public function webhook(string $provider, Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['message' => 'Invalid JSON body'], 400);
        }

        $providerRef = $payload['reference'] ?? null;

        $this->payments->recordWebhookEvent(
            $provider,
            is_string($providerRef) ? $providerRef : null,
            $payload,
            'RECEIVED'
        );

        return $this->json(['message' => 'Webhook reçu'], 200);
    }
}
