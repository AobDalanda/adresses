<?php

namespace App\Controller;

use App\Service\JwtAuthService;
use App\Service\ProfilePhotoStorage;
use App\Service\UserAccountAssetUrlResolver;
use App\Service\UserAccountService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/user')]
class UserAccountController extends AbstractController
{
    public function __construct(
        private JwtAuthService $jwt,
        private Connection $db,
        private UserAccountAssetUrlResolver $assetUrlResolver,
        private UserAccountService $userAccountService,
        private ProfilePhotoStorage $profilePhotoStorage
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
            SELECT id, phone, name, email, verified, account_type, profile_photo_path, identity_document_path, identity_document_number, driver_license_path, created_at
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
            'email' => isset($user['email']) && is_string($user['email']) && $user['email'] !== '' ? $user['email'] : null,
            'verified' => (bool) $user['verified'],
            'accountType' => (string) $user['account_type'],
            'profilePhotoPath' => isset($user['profile_photo_path']) ? (string) $user['profile_photo_path'] : null,
            'identityDocumentPath' => isset($user['identity_document_path']) ? (string) $user['identity_document_path'] : null,
            'identityDocumentNumber' => isset($user['identity_document_number']) && is_string($user['identity_document_number']) && $user['identity_document_number'] !== '' ? $user['identity_document_number'] : null,
            'driverLicensePath' => isset($user['driver_license_path']) ? (string) $user['driver_license_path'] : null,
            'createdAt' => $user['created_at'],
        ]));
    }

    #[Route('/me', methods: ['PATCH'])]
    public function updateMe(Request $request): JsonResponse
    {
        $auth = $this->jwt->decodeFromRequest($request);
        if (!$auth) {
            return $this->json(['message' => 'Unauthorized'], 401);
        }

        if (($auth['typ'] ?? null) !== 'mobile' || !isset($auth['uid'])) {
            return $this->json(['message' => 'Token non valide pour un utilisateur mobile'], 403);
        }

        $payload = $this->extractPayload($request);
        $hasName = array_key_exists('name', $payload);
        $hasEmail = array_key_exists('email', $payload);
        $profilePhoto = $request->files->get('profilePhoto');

        if (!$hasName && !$hasEmail && $profilePhoto === null) {
            return $this->json(['message' => 'Aucune donnée à mettre à jour'], 400);
        }

        $name = null;
        if ($hasName) {
            if (!is_string($payload['name']) || trim($payload['name']) === '') {
                return $this->json(['message' => 'name est invalide'], 400);
            }

            $name = trim(preg_replace('/\s+/', ' ', $payload['name']) ?? $payload['name']);
            if (mb_strlen($name) > 100) {
                return $this->json(['message' => 'name ne doit pas dépasser 100 caractères'], 400);
            }
        }

        $email = null;
        if ($hasEmail) {
            if ($payload['email'] !== null && !is_string($payload['email'])) {
                return $this->json(['message' => 'email est invalide'], 400);
            }

            $email = is_string($payload['email']) ? strtolower(trim($payload['email'])) : null;
            if ($email !== null && $email !== '') {
                if (mb_strlen($email) > 180) {
                    return $this->json(['message' => 'email ne doit pas dépasser 180 caractères'], 400);
                }

                if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    return $this->json(['message' => 'email est invalide'], 400);
                }
            } else {
                $email = '';
            }
        }

        $currentUser = $this->db->fetchAssociative(
            '
            SELECT profile_photo_path
            FROM user_account
            WHERE id = :id
            LIMIT 1
            ',
            ['id' => (int) $auth['uid']]
        );

        if ($currentUser === false) {
            return $this->json(['message' => 'Utilisateur introuvable'], 404);
        }

        $newProfilePhotoPath = null;
        if ($profilePhoto !== null) {
            if (!$profilePhoto instanceof UploadedFile) {
                return $this->json(['message' => 'profilePhoto est invalide'], 400);
            }

            try {
                $newProfilePhotoPath = $this->profilePhotoStorage->store($profilePhoto);
            } catch (\RuntimeException $e) {
                return $this->json(['message' => $e->getMessage()], 400);
            } catch (\Throwable) {
                return $this->json(['message' => 'Erreur lors de l’enregistrement de la photo de profil'], 500);
            }
        }

        try {
            $user = $this->userAccountService->updateCurrentUserProfile(
                (int) $auth['uid'],
                $name,
                $hasEmail ? $email : null,
                $newProfilePhotoPath
            );
        } catch (UniqueConstraintViolationException) {
            $this->profilePhotoStorage->deleteIfStored($newProfilePhotoPath);

            return $this->json(['message' => 'Cet email est déjà utilisé'], 409);
        } catch (\Throwable) {
            $this->profilePhotoStorage->deleteIfStored($newProfilePhotoPath);

            return $this->json(['message' => 'Erreur lors de la mise à jour du profil'], 500);
        }

        if ($user === null) {
            $this->profilePhotoStorage->deleteIfStored($newProfilePhotoPath);

            return $this->json(['message' => 'Utilisateur introuvable'], 404);
        }

        if (
            $newProfilePhotoPath !== null
            && isset($currentUser['profile_photo_path'])
            && is_string($currentUser['profile_photo_path'])
            && $currentUser['profile_photo_path'] !== ''
            && $currentUser['profile_photo_path'] !== $newProfilePhotoPath
        ) {
            $this->profilePhotoStorage->deleteIfStored($currentUser['profile_photo_path']);
        }

        return $this->json($this->assetUrlResolver->enrich($user));
    }

    /**
     * @return array<string, mixed>
     */
    private function extractPayload(Request $request): array
    {
        if (str_starts_with((string) $request->headers->get('Content-Type', ''), 'multipart/form-data')) {
            return $request->request->all();
        }

        $payload = json_decode($request->getContent(), true);

        return is_array($payload) ? $payload : [];
    }
}
