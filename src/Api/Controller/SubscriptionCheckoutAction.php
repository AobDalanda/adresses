<?php

namespace App\Api\Controller;

use App\Service\JwtAuthService;
use App\Service\PaymentService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class SubscriptionCheckoutAction
{
    public function __construct(
        private JwtAuthService $jwt,
        private PaymentService $payments
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['message' => 'Invalid JSON body'], 400);
        }

        $auth = $this->jwt->decodeFromRequest($request);
        if (!$auth) {
            return new JsonResponse(['message' => 'Unauthorized'], 401);
        }

        $provider = $payload['provider'] ?? null;
        $planCode = $payload['planCode'] ?? null;
        if (!is_string($provider) || $provider === '' || !is_string($planCode) || $planCode === '') {
            return new JsonResponse(['message' => 'provider et planCode sont requis'], 400);
        }

        [$ownerType, $ownerId] = $this->resolveOwner($auth);
        if ($ownerType === null) {
            return new JsonResponse(['message' => 'Invalid token type'], 403);
        }

        try {
            $result = $this->payments->initiateSubscriptionPayment($provider, $ownerType, $ownerId, $planCode);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['message' => $e->getMessage()], 400);
        } catch (\Throwable) {
            return new JsonResponse(['message' => 'Erreur de paiement'], 500);
        }

        return new JsonResponse($result, 201);
    }

    /**
     * @param array<string, mixed> $auth
     * @return array{0: string|null, 1: int}
     */
    private function resolveOwner(array $auth): array
    {
        if (($auth['typ'] ?? null) === 'mobile' && isset($auth['uid'])) {
            return ['USER', (int) $auth['uid']];
        }

        if (($auth['typ'] ?? null) === 'client' && isset($auth['cid'])) {
            return ['CLIENT', (int) $auth['cid']];
        }

        return [null, 0];
    }
}
