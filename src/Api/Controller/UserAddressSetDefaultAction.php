<?php

namespace App\Api\Controller;

use App\Repository\AddressRepository;
use App\Service\JwtAuthService;
use App\Service\UserAddressService;
use App\Util\AddressQrCodec;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class UserAddressSetDefaultAction
{
    public function __construct(
        private readonly JwtAuthService $jwt,
        private readonly AddressRepository $addresses,
        private readonly UserAddressService $userAddresses
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

        $addressId = null;
        if (isset($payload['addressId'])) {
            if (!is_numeric($payload['addressId'])) {
                return new JsonResponse(['message' => 'addressId doit être numérique'], 400);
            }

            $addressId = (int) $payload['addressId'];
        } elseif (isset($payload['identifier'])) {
            if (!is_string($payload['identifier']) || trim($payload['identifier']) === '') {
                return new JsonResponse(['message' => 'identifier est invalide'], 400);
            }

            $addressId = AddressQrCodec::decode($payload['identifier']);
            if ($addressId === null) {
                return new JsonResponse(['message' => 'identifier QR invalide'], 400);
            }
        } else {
            return new JsonResponse(['message' => 'addressId ou identifier est requis'], 400);
        }

        $address = $this->addresses->findById($addressId);
        if (!$address) {
            return new JsonResponse(['message' => 'Adresse non trouvée'], 404);
        }

        try {
            $updated = $this->userAddresses->setDefaultAddress((int) $auth['uid'], $addressId);
        } catch (\Throwable) {
            return new JsonResponse(['message' => 'Erreur lors de la mise à jour de l’adresse par défaut'], 500);
        }

        if (!$updated) {
            return new JsonResponse(['message' => 'Adresse non liée à cet utilisateur'], 404);
        }

        return new JsonResponse([
            'success' => true,
            'userId' => (int) $auth['uid'],
            'addressId' => (int) $address['id'],
            'identifier' => AddressQrCodec::encode((int) $address['id']),
            'addressCode' => $address['address_code'],
            'displayLabel' => $address['display_label'],
            'phoneDisplay' => $address['phone_display'],
            'contactPhone' => $address['contact_phone'],
            'isPrimary' => true,
        ]);
    }
}
