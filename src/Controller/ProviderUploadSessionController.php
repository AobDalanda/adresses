<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\Controller\AuthenticatedUserResolver;
use App\Service\ProviderUploadSessionService;
use App\Service\UploadStorageService;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v2/provider-upload-sessions')]
final class ProviderUploadSessionController
{
    public function __construct(
        private readonly AuthenticatedUserResolver $users,
        private readonly ProviderUploadSessionService $sessions,
    ) {
    }

    #[Route('', name: 'app_v2_provider_upload_session_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $user = $this->users->requireMobileUser($request);
        if ($user === null) {
            return new JsonResponse(['message' => 'Unauthorized'], 401);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['message' => 'Invalid JSON body'], 400);
        }

        $categories = $payload['allowedCategories'] ?? null;
        if (!is_array($categories) || $categories === [] || array_filter($categories, 'is_string') !== $categories) {
            return new JsonResponse([
                'message' => 'allowedCategories est requis',
                'allowedCategories' => UploadStorageService::categories(),
            ], 400);
        }
        if (isset($payload['maxFiles']) && !is_int($payload['maxFiles'])) {
            return new JsonResponse(['message' => 'maxFiles doit etre un entier'], 400);
        }
        if (isset($payload['maxBytes']) && !is_int($payload['maxBytes'])) {
            return new JsonResponse(['message' => 'maxBytes doit etre un entier'], 400);
        }

        try {
            $session = $this->sessions->create(
                (int) $user->getId(),
                array_values($categories),
                $payload['maxFiles'] ?? ProviderUploadSessionService::DEFAULT_MAX_FILES,
                $payload['maxBytes'] ?? ProviderUploadSessionService::DEFAULT_MAX_BYTES,
                isset($payload['applicationId']) && is_string($payload['applicationId'])
                    ? $payload['applicationId']
                    : null,
            );
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 400);
        } catch (\DomainException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 403);
        }

        return new JsonResponse(['uploadSession' => $session], 201);
    }

    #[Route('/{sessionId}/assets', name: 'app_v2_provider_upload_asset_create', methods: ['POST'])]
    public function upload(string $sessionId, Request $request): JsonResponse
    {
        $user = $this->users->requireMobileUser($request);
        if ($user === null) {
            return new JsonResponse(['message' => 'Unauthorized'], 401);
        }

        $category = $request->request->get('category');
        $file = $request->files->get('file');
        if (!is_string($category) || trim($category) === '') {
            return new JsonResponse(['message' => 'category est requis'], 400);
        }
        if (!$file instanceof UploadedFile) {
            return new JsonResponse(['message' => 'file est requis'], 400);
        }

        try {
            $asset = $this->sessions->upload((int) $user->getId(), $sessionId, trim($category), $file);
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 400);
        } catch (\DomainException $exception) {
            return new JsonResponse(
                ['message' => $exception->getMessage()],
                $exception->getCode() === 410 ? 410 : 409,
            );
        } catch (\Throwable) {
            return new JsonResponse(['message' => 'Erreur lors de l upload du fichier'], 500);
        }

        return new JsonResponse(['asset' => $asset], 201);
    }

    #[Route(
        '/{sessionId}/assets/{assetId}/consume',
        name: 'app_v2_provider_upload_asset_consume',
        methods: ['POST']
    )]
    public function consume(string $sessionId, string $assetId, Request $request): JsonResponse
    {
        $user = $this->users->requireMobileUser($request);
        if ($user === null) {
            return new JsonResponse(['message' => 'Unauthorized'], 401);
        }

        try {
            $this->sessions->consume((int) $user->getId(), $sessionId, $assetId);
        } catch (\DomainException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 409);
        }

        return new JsonResponse(['consumed' => true]);
    }
}
