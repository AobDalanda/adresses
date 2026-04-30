<?php

namespace App\Api\Controller;

use App\Service\JwtAuthService;
use App\Service\UserAddressService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class UserAddressExistsAction
{
    public function __construct(
        private JwtAuthService $jwt,
        private UserAddressService $userAddresses
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $auth = $this->jwt->decodeFromRequest($request);
        if (!$auth || ($auth['typ'] ?? null) !== 'mobile' || !isset($auth['uid'])) {
            return new JsonResponse(['message' => 'Unauthorized'], 401);
        }

        $address = $this->userAddresses->findUserAddress((int) $auth['uid']);
        if ($address === null) {
            return new JsonResponse([
                'hasAddress' => false,
                'message' => 'Aucune adresse trouvée pour cet utilisateur',
            ]);
        }

        return new JsonResponse([
            'hasAddress' => true,
            'address' => $address,
        ]);
    }
}
