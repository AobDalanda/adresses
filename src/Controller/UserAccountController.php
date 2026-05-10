<?php

namespace App\Controller;

use App\Service\JwtAuthService;
use App\Service\UserAccountAssetUrlResolver;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/user')]
class UserAccountController extends AbstractController
{
    public function __construct(
        private JwtAuthService $jwt,
        private Connection $db,
        private UserAccountAssetUrlResolver $assetUrlResolver
    ) {
    }

    #[Route('/me', methods: ['GET'])]
    public function me(Request $request): JsonResponse
    {
        $auth = $this->jwt->decodeFromRequest($request);
        if (!$auth) {
            return $this->json(['message' => 'Unauthorized'], 401);
        }

        if (($auth['typ'] ?? null) !== 'mobile' || !isset($auth['uid'])) {
            return $this->json(['message' => 'Token non valide pour un utilisateur mobile'], 403);
        }

        $userId = (int) $auth['uid'];

        $user = $this->db->fetchAssociative(
            "
            SELECT id, phone, name, verified, account_type, profile_photo_path, identity_document_path, driver_license_path, created_at
            FROM user_account
            WHERE id = :id
            LIMIT 1
            ",
            ['id' => $userId]
        );

        if (!$user) {
            return $this->json(['message' => 'Utilisateur introuvable'], 404);
        }

        return $this->json($this->assetUrlResolver->enrich([
            'id' => (int) $user['id'],
            'phone' => (string) $user['phone'],
            'name' => $user['name'],
            'verified' => (bool) $user['verified'],
            'accountType' => (string) $user['account_type'],
            'profilePhotoPath' => isset($user['profile_photo_path']) ? (string) $user['profile_photo_path'] : null,
            'identityDocumentPath' => isset($user['identity_document_path']) ? (string) $user['identity_document_path'] : null,
            'driverLicensePath' => isset($user['driver_license_path']) ? (string) $user['driver_license_path'] : null,
            'createdAt' => $user['created_at'],
        ]));
    }
}
