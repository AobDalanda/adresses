<?php

namespace App\Controller;

use App\Service\JwtAuthService;
use App\Service\PaymentService;
use App\Service\SubscriptionService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/subscription')]
class SubscriptionController extends AbstractController
{
    public function __construct(
        private JwtAuthService $jwt,
        private PaymentService $payments,
        private Connection $db,
        private SubscriptionService $subscriptions
    ) {
    }

    #[Route('/plans', methods: ['GET'])]
    public function listPlans(Request $request): JsonResponse
    {
        $auth = $this->jwt->decodeFromRequest($request);
        if (!$auth) {
            return $this->json(['message' => 'Unauthorized'], 401);
        }

        [$ownerType, $ownerId] = $this->resolveOwner($auth);
        if ($ownerType === null) {
            return $this->json(['message' => 'Invalid token type'], 403);
        }

        $rows = $this->db->fetchAllAssociative(
            "
            SELECT code, name, price_cents, currency, quota_create, quota_lookup
            FROM subscription_plan
            WHERE owner_type = :ownerType
            ORDER BY price_cents ASC
            ",
            ['ownerType' => $ownerType]
        );

        $plans = array_map(
            static function (array $row): array {
                return [
                    'code' => (string) $row['code'],
                    'name' => (string) $row['name'],
                    'priceCents' => (int) $row['price_cents'],
                    'currency' => (string) $row['currency'],
                    'quotaCreate' => $row['quota_create'] !== null ? (int) $row['quota_create'] : null,
                    'quotaLookup' => $row['quota_lookup'] !== null ? (int) $row['quota_lookup'] : null,
                ];
            },
            $rows
        );

        return $this->json(['data' => $plans]);
    }

    #[Route('/checkout', methods: ['POST'])]
    public function checkout(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['message' => 'Invalid JSON body'], 400);
        }

        $auth = $this->jwt->decodeFromRequest($request);
        if (!$auth) {
            return $this->json(['message' => 'Unauthorized'], 401);
        }

        $provider = $payload['provider'] ?? null;
        $planCode = $payload['planCode'] ?? null;
        if (!is_string($provider) || $provider === '' || !is_string($planCode) || $planCode === '') {
            return $this->json(['message' => 'provider et planCode sont requis'], 400);
        }

        [$ownerType, $ownerId] = $this->resolveOwner($auth);
        if ($ownerType === null) {
            return $this->json(['message' => 'Invalid token type'], 403);
        }

        try {
            $result = $this->payments->initiateSubscriptionPayment($provider, $ownerType, $ownerId, $planCode);
        } catch (\RuntimeException $e) {
            return $this->json(['message' => $e->getMessage()], 400);
        } catch (\Throwable) {
            return $this->json(['message' => 'Erreur de paiement'], 500);
        }

        return $this->json($result, 201);
    }

    #[Route('/status', methods: ['GET'])]
    public function status(Request $request): JsonResponse
    {
        $auth = $this->jwt->decodeFromRequest($request);
        if (!$auth) {
            return $this->json(['message' => 'Unauthorized'], 401);
        }

        [$ownerType, $ownerId] = $this->resolveOwner($auth);
        if ($ownerType === null) {
            return $this->json(['message' => 'Invalid token type'], 403);
        }

        $subscription = $this->subscriptions->getActiveSubscription($ownerType, $ownerId);
        if (!$subscription) {
            return $this->json([
                'active' => false,
                'subscription' => null,
            ]);
        }

        return $this->json([
            'active' => true,
            'subscription' => [
                'planCode' => (string) $subscription['plan_code'],
                'planName' => (string) $subscription['plan_name'],
                'priceCents' => (int) $subscription['price_cents'],
                'currency' => (string) $subscription['currency'],
                'status' => (string) $subscription['status'],
                'currentPeriodStart' => $subscription['current_period_start'],
                'currentPeriodEnd' => $subscription['current_period_end'],
                'quotaCreate' => $subscription['quota_create'] !== null ? (int) $subscription['quota_create'] : null,
                'quotaLookup' => $subscription['quota_lookup'] !== null ? (int) $subscription['quota_lookup'] : null,
            ],
        ]);
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
