<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;

class AccountDocumentStorage
{
    public function __construct(
        private SupabaseStorageClient $storage,
        private UploadedFileSecurityValidator $validator,
        private string $identityBucket,
        private string $driverLicenseBucket
    ) {
    }

    public function storeIdentityDocument(UploadedFile $file): string
    {
        return $this->storePdf($file, $this->identityBucket, 'identity-documents');
    }

    public function storeDriverLicense(UploadedFile $file): string
    {
        return $this->storePdf($file, $this->driverLicenseBucket, 'driver-licenses');
    }

    public function deleteIfStored(?string $publicPath): void
    {
        $this->storage->deleteIfStored($publicPath);
    }

    private function storePdf(UploadedFile $file, string $bucket, string $prefix): string
    {
        try {
            $validated = $this->validator->validateDocument($file);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'Le fichier ne doit pas dépasser 5 Mo') {
                throw new \RuntimeException('Le document ne doit pas dépasser 5 Mo', 0, $e);
            }

            throw $e;
        }

        return $this->storage->upload(
            $file,
            $bucket,
            $this->generateUniquePath($prefix, $validated['extension']),
            $validated['mimeType']
        );
    }

    private function generateUniquePath(string $prefix, string $extension): string
    {
        return sprintf('%s/%s.%s', trim($prefix, '/'), bin2hex(random_bytes(16)), $extension);
    }
}
