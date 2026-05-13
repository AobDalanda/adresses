<?php

namespace App\Api\Controller;

use App\Service\JwtAuthService;
use App\Service\ProfilePhotoStorage;
use App\Service\UserAccountAssetUrlResolver;
use App\Service\UserAccountService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class UserProfileUpdateAction
{
    public function __construct(
        private JwtAuthService $jwt,
        private Connection $db,
        private UserAccountService $userAccountService,
        private ProfilePhotoStorage $profilePhotoStorage,
        private UserAccountAssetUrlResolver $assetUrlResolver
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $auth = $this->jwt->decodeFromRequest($request);
        if (!$auth || ($auth['typ'] ?? null) !== 'mobile' || !isset($auth['uid'])) {
            return new JsonResponse(['message' => 'Unauthorized'], 401);
        }

        $payload = $this->extractPayload($request);
        $hasName = array_key_exists('name', $payload);
        $hasEmail = array_key_exists('email', $payload);
        $profilePhoto = $request->files->get('profilePhoto');

        if (!$hasName && !$hasEmail && $profilePhoto === null) {
            return new JsonResponse(['message' => 'Aucune donnée à mettre à jour'], 400);
        }

        $name = null;
        if ($hasName) {
            if (!is_string($payload['name']) || trim($payload['name']) === '') {
                return new JsonResponse(['message' => 'name est invalide'], 400);
            }

            $name = trim(preg_replace('/\s+/', ' ', $payload['name']) ?? $payload['name']);
            if (mb_strlen($name) > 100) {
                return new JsonResponse(['message' => 'name ne doit pas dépasser 100 caractères'], 400);
            }
        }

        $email = null;
        if ($hasEmail) {
            if ($payload['email'] !== null && !is_string($payload['email'])) {
                return new JsonResponse(['message' => 'email est invalide'], 400);
            }

            $email = is_string($payload['email']) ? strtolower(trim($payload['email'])) : null;
            if ($email !== null && $email !== '') {
                if (mb_strlen($email) > 180) {
                    return new JsonResponse(['message' => 'email ne doit pas dépasser 180 caractères'], 400);
                }

                if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    return new JsonResponse(['message' => 'email est invalide'], 400);
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
            return new JsonResponse(['message' => 'Utilisateur introuvable'], 404);
        }

        $newProfilePhotoPath = null;
        if ($profilePhoto !== null) {
            if (!$profilePhoto instanceof UploadedFile) {
                return new JsonResponse(['message' => 'profilePhoto est invalide'], 400);
            }

            try {
                $newProfilePhotoPath = $this->profilePhotoStorage->store($profilePhoto);
            } catch (\RuntimeException $e) {
                return new JsonResponse(['message' => $e->getMessage()], 400);
            } catch (\Throwable) {
                return new JsonResponse(['message' => 'Erreur lors de l’enregistrement de la photo de profil'], 500);
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

            return new JsonResponse(['message' => 'Cet email est déjà utilisé'], 409);
        } catch (\Throwable) {
            $this->profilePhotoStorage->deleteIfStored($newProfilePhotoPath);

            return new JsonResponse(['message' => 'Erreur lors de la mise à jour du profil'], 500);
        }

        if ($user === null) {
            $this->profilePhotoStorage->deleteIfStored($newProfilePhotoPath);

            return new JsonResponse(['message' => 'Utilisateur introuvable'], 404);
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

        return new JsonResponse($this->assetUrlResolver->enrich($user));
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
