<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

final class DeliveryPackageUploadService
{
    private const DELIVERY_UPLOAD_CATEGORIES = [
        'package_photo' => ['purpose' => 'DELIVERY_ORDER', 'allowedCategories' => '["package_photo"]'],
        'delivery_photo' => ['purpose' => 'DELIVERY_PROOF', 'allowedCategories' => '["delivery_photo"]'],
        'recipient_signature' => ['purpose' => 'DELIVERY_PROOF', 'allowedCategories' => '["recipient_signature"]'],
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly UploadStorageService $storage,
    ) {
    }

    /**
     * @return array{
     *     assetId: int,
     *     assetPublicId: string,
     *     sessionId: string,
     *     category: string,
     *     path: string,
     *     mimeType: string,
     *     extension: string,
     *     size: int,
     *     checksumSha256: string,
     *     validationStatus: string,
     *     consumed: bool
     * }
     */
    public function upload(int $userId, UploadedFile $file, string $category = 'package_photo'): array
    {
        $storedPath = null;
        $config = self::DELIVERY_UPLOAD_CATEGORIES[$category] ?? null;
        if ($config === null) {
            throw new \InvalidArgumentException('category est invalide');
        }

        try {
            return $this->db->transactional(function () use ($userId, $file, $category, $config, &$storedPath): array {
                $stored = $this->storage->store($category, $file);
                $storedPath = $stored['path'];
                $location = $this->parseStorageUri($stored['path']);
                $sessionPublicId = Uuid::v7()->toRfc4122();
                $sessionNonce = Uuid::v7()->toRfc4122();
                $assetPublicId = Uuid::v7()->toRfc4122();

                $sessionId = (int) $this->db->fetchOne(
                    <<<'SQL'
                        INSERT INTO upload_session (
                            public_id,
                            user_id,
                            provider_application_id,
                            purpose,
                            allowed_categories,
                            max_files,
                            max_bytes,
                            status,
                            expires_at,
                            nonce,
                            created_at,
                            updated_at
                        )
                        VALUES (
                            :publicId,
                            :userId,
                            NULL,
                            :purpose,
                            CAST(:allowedCategories AS jsonb),
                            1,
                            :maxBytes,
                            'COMPLETED',
                            now() + interval '1 day',
                            :nonce,
                            now(),
                            now()
                        )
                        RETURNING id
                        SQL,
                    [
                        'publicId' => $sessionPublicId,
                        'userId' => $userId,
                        'purpose' => $config['purpose'],
                        'allowedCategories' => $config['allowedCategories'],
                        'maxBytes' => $stored['size'],
                        'nonce' => $sessionNonce,
                    ]
                );

                $assetId = (int) $this->db->fetchOne(
                    <<<'SQL'
                        INSERT INTO uploaded_asset (
                            public_id,
                            session_id,
                            category,
                            bucket,
                            object_key,
                            mime_type,
                            extension,
                            size_bytes,
                            checksum_sha256,
                            validation_status,
                            created_at
                        )
                        VALUES (
                            :publicId,
                            :sessionId,
                            :category,
                            :bucket,
                            :objectKey,
                            :mimeType,
                            :extension,
                            :sizeBytes,
                            :checksum,
                            'VALID',
                            now()
                        )
                        RETURNING id
                        SQL,
                    [
                        'publicId' => $assetPublicId,
                        'sessionId' => $sessionId,
                        'category' => $category,
                        'bucket' => $location['bucket'],
                        'objectKey' => $location['objectKey'],
                        'mimeType' => $stored['mimeType'],
                        'extension' => $stored['extension'],
                        'sizeBytes' => $stored['size'],
                        'checksum' => $stored['checksumSha256'],
                    ]
                );

                return [
                    'assetId' => $assetId,
                    'assetPublicId' => $assetPublicId,
                    'sessionId' => $sessionPublicId,
                    'category' => $category,
                    'path' => $stored['path'],
                    'mimeType' => $stored['mimeType'],
                    'extension' => $stored['extension'],
                    'size' => $stored['size'],
                    'checksumSha256' => $stored['checksumSha256'],
                    'validationStatus' => 'VALID',
                    'consumed' => false,
                ];
            });
        } catch (\Throwable $exception) {
            if ($storedPath !== null) {
                try {
                    $this->storage->deleteIfStored($storedPath);
                } catch (\Throwable) {
                }
            }

            throw $exception;
        }
    }

    /**
     * @return array{bucket: string, objectKey: string}
     */
    private function parseStorageUri(string $storageUri): array
    {
        if (!str_starts_with($storageUri, 'supabase://')) {
            throw new \UnexpectedValueException('Le stockage n a pas retourne une reference privee valide.');
        }

        $parts = explode('/', substr($storageUri, strlen('supabase://')), 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new \UnexpectedValueException('Le stockage n a pas retourne une reference privee valide.');
        }

        return ['bucket' => $parts[0], 'objectKey' => $parts[1]];
    }
}
