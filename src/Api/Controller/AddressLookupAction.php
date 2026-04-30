<?php

namespace App\Api\Controller;

use App\Repository\AddressRepository;
use App\Service\JwtAuthService;
use App\Service\SubscriptionService;
use App\Service\UsageService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class AddressLookupAction
{
    public function __construct(
        private AddressRepository $repo,
        private JwtAuthService $jwt,
        private SubscriptionService $subscriptions,
        private UsageService $usage
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $auth = $this->jwt->decodeFromRequest($request);
        if (!$auth || ($auth['typ'] ?? null) !== 'client' || !isset($auth['cid'])) {
            return new JsonResponse(['message' => 'Unauthorized'], 401);
        }

        $latParam = $request->query->get('lat');
        $lngParam = $request->query->get('lng');
        if ($latParam === null || $latParam === '' || $lngParam === null || $lngParam === '') {
            return new JsonResponse(['message' => 'lat et lng sont requis'], 400);
        }

        if (!is_numeric($latParam) || !is_numeric($lngParam)) {
            return new JsonResponse(['message' => 'lat et lng doivent être numériques'], 400);
        }

        $lat = (float) $latParam;
        $lng = (float) $lngParam;
        if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
            return new JsonResponse(['message' => 'lat ou lng hors limites'], 400);
        }

        $subscription = $this->subscriptions->getActiveSubscription('CLIENT', (int) $auth['cid']);
        if (!$subscription) {
            return new JsonResponse(['message' => 'Abonnement requis'], 403);
        }

        if ($subscription['quota_lookup'] !== null) {
            $count = $this->usage->incrementLookupUsage((int) $auth['cid'], $subscription, (int) $subscription['quota_lookup']);
            if ($count === null) {
                return new JsonResponse(['message' => 'Quota dépassé'], 429);
            }
        }

        $address = $this->repo->findNearest($lat, $lng);
        $area = $this->repo->findAdminArea($lat, $lng);

        if (!$address) {
            return new JsonResponse(['message' => 'Adresse non trouvée'], 404);
        }

        return new JsonResponse([
            'displayLabel' => $address['display_label'],
            'distanceMeters' => round($address['distance'], 2),
            'adminArea' => $area,
        ]);
    }
}
