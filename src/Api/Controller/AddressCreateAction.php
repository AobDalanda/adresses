<?php

namespace App\Api\Controller;

use App\Service\CreateAddressService;
use App\Service\JwtAuthService;
use App\Service\SubscriptionService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class AddressCreateAction
{
    public function __construct(
        private CreateAddressService $createAddress,
        private JwtAuthService $jwt,
        private SubscriptionService $subscriptions
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $auth = $this->jwt->decodeFromRequest($request);
        if (!$auth || ($auth['typ'] ?? null) !== 'mobile' || !isset($auth['uid'])) {
            return new JsonResponse(['message' => 'Unauthorized'], 401);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['message' => 'Invalid JSON body'], 400);
        }

        $gpsPoints = $payload['gpsPoints'] ?? null;
        if (!is_array($gpsPoints)) {
            return new JsonResponse(['message' => 'gpsPoints est requis'], 400);
        }

        $normalizedPoints = [];
        foreach ($gpsPoints as $p) {
            if (!is_array($p)) {
                return new JsonResponse(['message' => 'gpsPoints invalide'], 400);
            }

            $lat = $p['lat'] ?? null;
            $lng = $p['lng'] ?? null;
            if (!is_numeric($lat) || !is_numeric($lng)) {
                return new JsonResponse(['message' => 'lat et lng doivent être numériques'], 400);
            }

            $lat = (float) $lat;
            $lng = (float) $lng;
            if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
                return new JsonResponse(['message' => 'lat ou lng hors limites'], 400);
            }

            $accuracy = $p['accuracy'] ?? null;
            if ($accuracy !== null && !is_numeric($accuracy)) {
                return new JsonResponse(['message' => 'accuracy doit être numérique'], 400);
            }

            $source = $p['source'] ?? null;
            if ($source !== null && !is_string($source)) {
                return new JsonResponse(['message' => 'source doit être une chaîne'], 400);
            }

            $normalizedPoints[] = [
                'lat' => $lat,
                'lng' => $lng,
                'accuracy' => $accuracy !== null ? (float) $accuracy : null,
                'source' => $source,
            ];
        }

        $subscription = $this->subscriptions->getActiveSubscription('USER', (int) $auth['uid']);
        if (!$subscription) {
            return new JsonResponse(['message' => 'Abonnement requis'], 403);
        }

        $phone = (string) ($auth['sub'] ?? '');
        if ($phone === '') {
            return new JsonResponse(['message' => 'Token invalide'], 401);
        }

        try {
            $result = $this->createAddress->create($phone, $normalizedPoints, $request->getClientIp());
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['message' => $e->getMessage()], 400);
        } catch (\Throwable) {
            return new JsonResponse(['message' => 'Erreur lors de la création de l’adresse'], 500);
        }

        return new JsonResponse($result, 201);
    }
}
