<?php

namespace App\Controller;

use App\Service\JwtAuthService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/auth/client')]
class ClientAuthController extends AbstractController
{
    public function __construct(
        private Connection $db,
        private JwtAuthService $jwt
    ) {
    }

    #[Route('/login', methods: ['POST'])]
    public function login(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['message' => 'Invalid JSON body'], 400);
        }

        $clientId = $payload['clientId'] ?? null;
        $clientSecret = $payload['clientSecret'] ?? null;
        if (!is_string($clientId) || $clientId === '' || !is_string($clientSecret) || $clientSecret === '') {
            return $this->json(['message' => 'clientId et clientSecret sont requis'], 400);
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
            return $this->json(['message' => 'Identifiants invalides'], 401);
        }

        $token = $this->jwt->issueToken([
            'sub' => $clientId,
            'typ' => 'client',
            'cid' => (int) $client['id'],
        ]);

        return $this->json(['token' => $token]);
    }
}
