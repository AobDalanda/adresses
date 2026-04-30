<?php

namespace App\Api\Controller;

use App\Service\CreateConnectedUserAddressService;
use App\Service\JwtAuthService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class UserAddressCreateAction
{
    public function __construct(
        private JwtAuthService $jwt,
        private CreateConnectedUserAddressService $createAddress
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

        $label = $payload['label'] ?? null;
        $latitude = $payload['latitude'] ?? null;
        $longitude = $payload['longitude'] ?? null;

        if (!is_string($label) || trim($label) === '') {
            return new JsonResponse(['message' => 'label est requis'], 400);
        }

        if (!is_numeric($latitude) || !is_numeric($longitude)) {
            return new JsonResponse(['message' => 'latitude et longitude sont requis'], 400);
        }

        $latitude = (float) $latitude;
        $longitude = (float) $longitude;

        if ($latitude < -90.0 || $latitude > 90.0 || $longitude < -180.0 || $longitude > 180.0) {
            return new JsonResponse(['message' => 'latitude ou longitude hors limites'], 400);
        }

        if (isset($payload['accuracy']) && $payload['accuracy'] !== null && !is_numeric($payload['accuracy'])) {
            return new JsonResponse(['message' => 'accuracy doit être numérique'], 400);
        }

        if (isset($payload['description']) && $payload['description'] !== null && !is_string($payload['description'])) {
            return new JsonResponse(['message' => 'description doit être une chaîne'], 400);
        }

        if (isset($payload['plus_code']) && $payload['plus_code'] !== null && !is_string($payload['plus_code'])) {
            return new JsonResponse(['message' => 'plus_code doit être une chaîne'], 400);
        }

        if (isset($payload['source']) && $payload['source'] !== null && !is_string($payload['source'])) {
            return new JsonResponse(['message' => 'source doit être une chaîne'], 400);
        }

        $phone = (string) ($auth['sub'] ?? '');
        if ($phone === '') {
            return new JsonResponse(['message' => 'Token invalide'], 401);
        }

        try {
            $result = $this->createAddress->createForUser(
                (int) $auth['uid'],
                $phone,
                [
                    'label' => trim($label),
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'description' => $payload['description'] ?? null,
                    'plus_code' => $payload['plus_code'] ?? null,
                    'accuracy' => isset($payload['accuracy']) ? (float) $payload['accuracy'] : null,
                    'source' => $payload['source'] ?? null,
                    'isDefault' => $payload['isDefault'] ?? true,
                ],
                $request->getClientIp()
            );
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['message' => $e->getMessage()], 400);
        } catch (\Throwable) {
            return new JsonResponse(['message' => 'Erreur lors de la création de l’adresse utilisateur'], 500);
        }

        return new JsonResponse($result, 201);
    }
}
