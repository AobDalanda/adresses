<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

final class DeliveryPackageUploadService
{
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
    public function upload(int $userId, UploadedFile $file): array
    {
        $storedPath = null;

        try {
            return $this->db->transactional(function () use ($userId, $file, &$storedPath): array {
                $stored = $this->storage->store('package_photo', $file);
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
                            'DELIVERY_ORDER',
                            '["package_photo"]'::jsonb,
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
                            'package_photo',
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
                    'category' => 'package_photo',
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
