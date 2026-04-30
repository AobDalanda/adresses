<?php

namespace App\Controller;

use App\Repository\AddressRepository;
use App\Service\CreateAddressService;
use App\Service\JwtAuthService;
use App\Service\SubscriptionService;
use App\Service\UsageService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/address')]
class AddressController extends AbstractController
{
    public function __construct(
        private AddressRepository $repo,
        private CreateAddressService $createAddress,
        private JwtAuthService $jwt,
        private SubscriptionService $subscriptions,
        private UsageService $usage
    )
    {
    }

    #[Route('/lookup', methods: ['GET'])]
    public function lookup(Request $request): JsonResponse
    {
        $auth = $this->jwt->decodeFromRequest($request);
        if (!$auth || ($auth['typ'] ?? null) !== 'client' || !isset($auth['cid'])) {
            return $this->json(['message' => 'Unauthorized'], 401);
        }

        $latParam = $request->query->get('lat');
        $lngParam = $request->query->get('lng');
        if ($latParam === null || $latParam === '' || $lngParam === null || $lngParam === '') {
            return $this->json(['message' => 'lat et lng sont requis'], 400);
        }

        if (!is_numeric($latParam) || !is_numeric($lngParam)) {
            return $this->json(['message' => 'lat et lng doivent être numériques'], 400);
        }

        $lat = (float) $latParam;
        $lng = (float) $lngParam;
        if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
            return $this->json(['message' => 'lat ou lng hors limites'], 400);
        }

        $subscription = $this->subscriptions->getActiveSubscription('CLIENT', (int) $auth['cid']);
        if (!$subscription) {
            return $this->json(['message' => 'Abonnement requis'], 403);
        }

        if ($subscription['quota_lookup'] !== null) {
            $count = $this->usage->incrementLookupUsage((int) $auth['cid'], $subscription, (int) $subscription['quota_lookup']);
            if ($count === null) {
                return $this->json(['message' => 'Quota dépassé'], 429);
            }
        }

        $address = $this->repo->findNearest($lat, $lng);
        $area = $this->repo->findAdminArea($lat, $lng);

        if (!$address) {
            return $this->json(['message' => 'Adresse non trouvée'], 404);
        }

        return $this->json([
            'displayLabel' => $address['display_label'],
            'distanceMeters' => round($address['distance'], 2),
            'adminArea' => $area,
        ]);
    }

    #[Route('', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $auth = $this->jwt->decodeFromRequest($request);
        if (!$auth || ($auth['typ'] ?? null) !== 'mobile' || !isset($auth['uid'])) {
            return $this->json(['message' => 'Unauthorized'], 401);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['message' => 'Invalid JSON body'], 400);
        }

        $gpsPoints = $payload['gpsPoints'] ?? null;
        if (!is_array($gpsPoints)) {
            return $this->json(['message' => 'gpsPoints est requis'], 400);
        }

        $normalizedPoints = [];
        foreach ($gpsPoints as $p) {
            if (!is_array($p)) {
                return $this->json(['message' => 'gpsPoints invalide'], 400);
            }

            $lat = $p['lat'] ?? null;
            $lng = $p['lng'] ?? null;
            if (!is_numeric($lat) || !is_numeric($lng)) {
                return $this->json(['message' => 'lat et lng doivent être numériques'], 400);
            }

            $lat = (float) $lat;
            $lng = (float) $lng;
            if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
                return $this->json(['message' => 'lat ou lng hors limites'], 400);
            }

            $accuracy = $p['accuracy'] ?? null;
            if ($accuracy !== null && !is_numeric($accuracy)) {
                return $this->json(['message' => 'accuracy doit être numérique'], 400);
            }

            $source = $p['source'] ?? null;
            if ($source !== null && !is_string($source)) {
                return $this->json(['message' => 'source doit être une chaîne'], 400);
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
            return $this->json(['message' => 'Abonnement requis'], 403);
        }

        $phone = (string) ($auth['sub'] ?? '');
        if ($phone === '') {
            return $this->json(['message' => 'Token invalide'], 401);
        }

        try {
            $result = $this->createAddress->create($phone, $normalizedPoints, $request->getClientIp());
        } catch (\InvalidArgumentException $e) {
            return $this->json(['message' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            return $this->json(['message' => 'Erreur lors de la création de l’adresse'], 500);
        }

        return $this->json($result, 201);
    }
}
