<?php

namespace App\Api\Controller;

use App\Service\JwtAuthService;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class PricingCatalogAction
{
    public function __construct(
        private JwtAuthService $jwt,
        private Connection $db
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $auth = $this->jwt->decodeFromRequest($request);
        if (!$auth || ($auth['typ'] ?? null) !== 'mobile' || !isset($auth['uid'])) {
            return new JsonResponse(['message' => 'Unauthorized'], 401);
        }

        return new JsonResponse([
            'serviceTypes' => $this->db->fetchAllAssociative(
                'SELECT code, name, description FROM service_types WHERE is_active = TRUE ORDER BY id ASC'
            ),
            'vehicleTypes' => $this->db->fetchAllAssociative(
                'SELECT code, name, description FROM vehicle_types WHERE is_active = TRUE ORDER BY id ASC'
            ),
            'zones' => $this->db->fetchAllAssociative(
                'SELECT id, name, parent_zone_id AS "parentZoneId" FROM zones ORDER BY COALESCE(parent_zone_id, 0), id ASC'
            ),
        ]);
    }
}
