<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;

final class UploadStorageService
{
    private const MAX_FILE_SIZE_BYTES = UploadedFileSecurityValidator::MAX_FILE_SIZE_BYTES;

    private const CATEGORY_TO_DIRECTORY = [
        'identity_document' => 'identity-documents',
        'driver_license' => 'driver-licenses',
        'vehicle_insurance' => 'vehicle-docs',
        'vehicle_registration' => 'vehicle-docs',
        'vehicle_registration_front' => 'vehicle-docs',
        'vehicle_registration_back' => 'vehicle-docs',
        'vehicle_photo' => 'vehicle-photos',
        'package_photo' => 'package-photos',
        'delivery_photo' => 'delivery-proofs',
        'recipient_signature' => 'delivery-proofs',
        'profile_photo' => 'profile-photos',
    ];

    private const CATEGORY_ALLOWED_EXTENSIONS = [
        'identity_document' => ['jpg', 'jpeg', 'png', 'pdf'],
        'driver_license' => ['jpg', 'jpeg', 'png', 'pdf'],
        'vehicle_insurance' => ['jpg', 'jpeg', 'png', 'pdf'],
        'vehicle_registration' => ['jpg', 'jpeg', 'png', 'pdf'],
        'vehicle_registration_front' => ['jpg', 'jpeg', 'png'],
        'vehicle_registration_back' => ['jpg', 'jpeg', 'png'],
        'vehicle_photo' => ['jpg', 'jpeg', 'png', 'webp'],
        'package_photo' => ['jpg', 'jpeg', 'png', 'webp'],
        'delivery_photo' => ['jpg', 'jpeg', 'png', 'webp'],
        'recipient_signature' => ['jpg', 'jpeg', 'png', 'webp'],
        'profile_photo' => ['jpg', 'jpeg', 'png', 'webp'],
    ];

    public function __construct(
        private readonly AccountDocumentStorage $accountDocuments,
        private readonly ProfilePhotoStorage $profilePhotos,
        private readonly UploadedFileSecurityValidator $validator,
    ) {
    }

    /**
     * @return array{
     *     category: string,
     *     path: string,
     *     mimeType: string,
     *     extension: string,
     *     size: int,
     *     checksumSha256: string
     * }
     */
    public function store(string $category, UploadedFile $file): array
    {
        $directory = self::CATEGORY_TO_DIRECTORY[$category] ?? null;
        if ($directory === null) {
            throw new \InvalidArgumentException('category est invalide');
        }

        $size = $file->getSize();
        if (!is_int($size) || $size <= 0) {
            throw new \InvalidArgumentException('file est vide ou invalide');
        }

        if ($size > self::MAX_FILE_SIZE_BYTES) {
            throw new \InvalidArgumentException('file dépasse la taille maximale autorisée');
        }

        try {
            $validated = in_array($category, ['vehicle_photo', 'package_photo', 'delivery_photo', 'recipient_signature', 'profile_photo'], true)
                ? $this->validator->validatePhoto($file)
                : $this->validator->validateDocument($file);
        } catch (\RuntimeException $exception) {
            throw new \InvalidArgumentException($exception->getMessage(), 0, $exception);
        }

        $allowedExtensions = self::CATEGORY_ALLOWED_EXTENSIONS[$category];
        if (!in_array($validated['extension'], $allowedExtensions, true)) {
            throw new \InvalidArgumentException('type de fichier non autorisé pour cette catégorie');
        }

        $checksum = hash_file('sha256', $file->getPathname());
        if (!is_string($checksum)) {
            throw new \RuntimeException('Impossible de calculer l intégrité du fichier');
        }

        if ($category === 'identity_document') {
            $path = $this->accountDocuments->storeIdentityDocument($file);
        } elseif ($category === 'driver_license') {
            $path = $this->accountDocuments->storeDriverLicense($file);
        } elseif ($category === 'profile_photo') {
            $path = $this->profilePhotos->store($file);
        } else {
            $path = $this->accountDocuments->store($file, $directory);
        }

        return [
            'category' => $category,
            'path' => $path,
            'mimeType' => $validated['mimeType'],
            'extension' => $validated['extension'],
            'size' => $size,
            'checksumSha256' => $checksum,
        ];
    }

    public function deleteIfStored(?string $path): void
    {
        $this->accountDocuments->deleteIfStored($path);
    }

    /**
     * @return list<string>
     */
    public static function categories(): array
    {
        return array_keys(self::CATEGORY_TO_DIRECTORY);
    }
}
