<?php

namespace App\Api\Controller;

use App\Repository\AddressRepository;
use App\Service\JwtAuthService;
use App\Util\AddressQrCodec;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class AddressResolveAction
{
    public function __construct(
        private AddressRepository $repo,
        private JwtAuthService $jwt
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        if (!$this->jwt->decodeFromRequest($request)) {
            return new JsonResponse(['message' => 'Unauthorized'], 401);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['message' => 'Invalid JSON body'], 400);
        }

        $identifier = $payload['identifier'] ?? null;
        if (!is_string($identifier) || trim($identifier) === '') {
            return new JsonResponse(['message' => 'identifier est requis'], 400);
        }

        $addressId = AddressQrCodec::decode($identifier);
        if ($addressId === null) {
            return new JsonResponse(['message' => 'identifier QR invalide'], 400);
        }

        $address = $this->repo->findById($addressId);
        if (!$address) {
            return new JsonResponse(['message' => 'Adresse non trouvée'], 404);
        }

        return new JsonResponse([
            'addressId' => $address['id'],
            'identifier' => AddressQrCodec::encode((int) $address['id']),
            'addressCode' => $address['address_code'],
            'displayLabel' => $address['display_label'],
            'phoneDisplay' => $address['phone_display'],
            'createdAt' => $address['created_at'],
        ]);
    }
}
