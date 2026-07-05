<?php

namespace App\Api\Controller;

use App\Service\DeliveryAssetUploadService;
use App\Service\JwtAuthService;
use App\Service\UploadStorageService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class UploadFileAction
{
    public function __construct(
        private readonly JwtAuthService $jwt,
        private readonly UploadStorageService $uploads,
        private readonly DeliveryAssetUploadService $deliveryAssetUploads,
        private readonly LoggerInterface $logger
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $category = $request->request->get('category');
        $file = $request->files->get('file');

        if (!is_string($category) || trim($category) === '') {
            return new JsonResponse([
                'message' => 'category est requis',
                'allowedCategories' => UploadStorageService::categories(),
            ], 400);
        }

        if (!$file instanceof UploadedFile) {
            return new JsonResponse(['message' => 'file est requis'], 400);
        }

        try {
            $normalizedCategory = trim($category);
            if (in_array($normalizedCategory, ['package_photo', 'delivery_photo', 'recipient_signature'], true)) {
                $auth = $this->jwt->decodeFromRequest($request);
                if (!$auth || ($auth['typ'] ?? null) !== 'mobile' || !isset($auth['uid'])) {
                    return new JsonResponse(['message' => 'Unauthorized'], 401);
                }

                $stored = $this->deliveryAssetUploads->upload((int) $auth['uid'], $file, $normalizedCategory);

                return new JsonResponse([
                    'success' => true,
                    'category' => $stored['category'],
                    'assetId' => $stored['assetId'],
                    'assetPublicId' => $stored['assetPublicId'],
                    'sessionId' => $stored['sessionId'],
                    'path' => $stored['path'],
                    'mimeType' => $stored['mimeType'],
                    'size' => $stored['size'],
                    'validationStatus' => $stored['validationStatus'],
                    'consumed' => $stored['consumed'],
                ], 201);
            }

            $stored = $this->uploads->store($normalizedCategory, $file);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse([
                'message' => $e->getMessage(),
                'allowedCategories' => UploadStorageService::categories(),
            ], 400);
        } catch (\Throwable $e) {
            $this->logger->error('Échec du stockage du fichier uploadé.', [
                'category' => $category,
                'exception' => $e,
            ]);
            return new JsonResponse(['message' => 'Erreur lors de l’upload du fichier'], 500);
        }

        return new JsonResponse([
            'success' => true,
            'category' => $stored['category'],
            'path' => $stored['path'],
            'mimeType' => $stored['mimeType'],
            'size' => $stored['size'],
        ], 201);
    }
}
