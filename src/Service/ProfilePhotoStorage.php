<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;

class ProfilePhotoStorage
{
    public function __construct(
        private SupabaseStorageClient $storage,
        private UploadedFileSecurityValidator $validator,
        private string $bucket
    ) {
    }

    public function store(UploadedFile $file): string
    {
        try {
            $validated = $this->validator->validatePhoto($file);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'Le fichier ne doit pas dépasser 5 Mo') {
                throw new \RuntimeException('La photo de profil ne doit pas dépasser 5 Mo', 0, $e);
            }

            throw $e;
        }

        return $this->storage->upload(
            $file,
            $this->bucket,
            $this->generateUniquePath('profile-photos', $validated['extension']),
            $validated['mimeType']
        );
    }

    public function deleteIfStored(?string $publicPath): void
    {
        $this->storage->deleteIfStored($publicPath);
    }

    private function generateUniquePath(string $prefix, string $extension): string
    {
        return sprintf('%s/%s.%s', trim($prefix, '/'), bin2hex(random_bytes(16)), $extension);
    }
}
