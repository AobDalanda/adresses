<?php

namespace App\Api\Controller;

use App\Service\UploadStorageService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class UploadFileAction
{
    public function __construct(
        private readonly UploadStorageService $uploads,
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
            $stored = $this->uploads->store(trim($category), $file);
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
