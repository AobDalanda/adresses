<?php

namespace App\Api\Controller;

use App\Service\JwtAuthService;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class ClientAuthLoginAction
{
    public function __construct(
        private Connection $db,
        private JwtAuthService $jwt
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['message' => 'Invalid JSON body'], 400);
        }

        $clientId = $payload['clientId'] ?? null;
        $clientSecret = $payload['clientSecret'] ?? null;
        if (!is_string($clientId) || $clientId === '' || !is_string($clientSecret) || $clientSecret === '') {
            return new JsonResponse(['message' => 'clientId et clientSecret sont requis'], 400);
        }

        $client = $this->db->fetchAssociative(
            "
            SELECT id, client_secret_hash
            FROM api_client
            WHERE client_id = :clientId
            LIMIT 1
            ",
            ['clientId' => $clientId]
        );

        if (!$client || !password_verify($clientSecret, $client['client_secret_hash'])) {
            return new JsonResponse(['message' => 'Identifiants invalides'], 401);
        }

        $token = $this->jwt->issueToken([
            'sub' => $clientId,
            'typ' => 'client',
            'cid' => (int) $client['id'],
        ]);

        return new JsonResponse(['token' => $token]);
    }
}
