<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

class ProviderUploadSessionService
{
    public const DEFAULT_MAX_FILES = 10;
    public const MAX_FILES = 20;
    public const DEFAULT_MAX_BYTES = 26_214_400;
    public const MAX_BYTES = 52_428_800;

    public function __construct(
        private readonly Connection $db,
        private readonly UploadStorageService $storage,
        private readonly int $ttlSeconds,
    ) {
    }

    /**
     * @param list<string> $categories
     * @return array<string, mixed>
     */
    public function create(
        int $userId,
        array $categories,
        int $maxFiles = self::DEFAULT_MAX_FILES,
        int $maxBytes = self::DEFAULT_MAX_BYTES,
        ?string $applicationPublicId = null,
    ): array {
        $categories = array_values(array_unique($categories));
        if ($categories === []) {
            throw new \InvalidArgumentException('allowedCategories doit contenir au moins une categorie.');
        }

        $unknown = array_values(array_diff($categories, UploadStorageService::categories()));
        if ($unknown !== []) {
            throw new \InvalidArgumentException(sprintf('Categorie non autorisee: %s.', $unknown[0]));
        }
        if ($maxFiles < 1 || $maxFiles > self::MAX_FILES) {
            throw new \InvalidArgumentException(sprintf('maxFiles doit etre compris entre 1 et %d.', self::MAX_FILES));
        }
        if ($maxBytes < 1 || $maxBytes > self::MAX_BYTES) {
            throw new \InvalidArgumentException(sprintf('maxBytes doit etre compris entre 1 et %d.', self::MAX_BYTES));
        }

        $applicationId = null;
        if ($applicationPublicId !== null) {
            $applicationId = $this->db->fetchOne(
                <<<'SQL'
                    SELECT application.id
                    FROM provider_application application
                    JOIN provider_profile profile ON profile.id = application.provider_profile_id
                    WHERE application.public_id = :publicId
                      AND profile.user_id = :userId
                    SQL,
                ['publicId' => $applicationPublicId, 'userId' => $userId],
            );
            if ($applicationId === false) {
                throw new \DomainException('Candidature prestataire introuvable pour cet utilisateur.');
            }
        }

        $publicId = Uuid::v7()->toRfc4122();
        $nonce = Uuid::v7()->toRfc4122();
        $expiresAt = (new \DateTimeImmutable(sprintf('+%d seconds', $this->ttlSeconds)))
            ->format(\DateTimeInterface::ATOM);

        $this->db->transactional(function () use (
            $publicId,
            $userId,
            $applicationId,
            $categories,
            $maxFiles,
            $maxBytes,
            $expiresAt,
            $nonce,
        ): void {
            $this->db->executeStatement(
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
                        :applicationId,
                        'PROVIDER_APPLICATION',
                        CAST(:categories AS jsonb),
                        :maxFiles,
                        :maxBytes,
                        'OPEN',
                        :expiresAt,
                        :nonce,
                        now(),
                        now()
                    )
                    SQL,
                [
                    'publicId' => $publicId,
                    'userId' => $userId,
                    'applicationId' => $applicationId === null ? null : (int) $applicationId,
                    'categories' => $this->encodeJson($categories),
                    'maxFiles' => $maxFiles,
                    'maxBytes' => $maxBytes,
                    'expiresAt' => $expiresAt,
                    'nonce' => $nonce,
                ],
            );
            $this->recordOutbox(
                'upload_session',
                $publicId,
                'provider.upload_session.created',
                [
                    'sessionId' => $publicId,
                    'userId' => $userId,
                    'purpose' => 'PROVIDER_APPLICATION',
                    'expiresAt' => $expiresAt,
                ],
            );
        });

        return [
            'sessionId' => $publicId,
            'purpose' => 'PROVIDER_APPLICATION',
            'allowedCategories' => $categories,
            'maxFiles' => $maxFiles,
            'maxBytes' => $maxBytes,
            'status' => 'OPEN',
            'expiresAt' => $expiresAt,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function upload(int $userId, string $sessionPublicId, string $category, UploadedFile $file): array
    {
        $storedPath = null;

        try {
            return $this->db->transactional(function () use (
                $userId,
                $sessionPublicId,
                $category,
                $file,
                &$storedPath,
            ): array {
                $session = $this->db->fetchAssociative(
                    <<<'SQL'
                        SELECT id, allowed_categories, max_files, max_bytes, status, expires_at
                        FROM upload_session
                        WHERE public_id = :publicId
                          AND user_id = :userId
                        FOR UPDATE
                        SQL,
                    ['publicId' => $sessionPublicId, 'userId' => $userId],
                );
                if ($session === false) {
                    throw new \DomainException('Session d upload introuvable.');
                }

                $this->assertOpenSession($session);
                $allowedCategories = $this->decodeStringList($session['allowed_categories']);
                if (!in_array($category, $allowedCategories, true)) {
                    throw new \DomainException('Cette categorie n est pas autorisee par la session.');
                }

                $usage = $this->db->fetchAssociative(
                    <<<'SQL'
                        SELECT COUNT(*) AS file_count, COALESCE(SUM(size_bytes), 0) AS byte_count
                        FROM uploaded_asset
                        WHERE session_id = :sessionId
                        SQL,
                    ['sessionId' => (int) $session['id']],
                );
                $fileCount = (int) ($usage['file_count'] ?? 0);
                $byteCount = (int) ($usage['byte_count'] ?? 0);
                $fileSize = $file->getSize();
                if (!is_int($fileSize) || $fileSize <= 0) {
                    throw new \InvalidArgumentException('file est vide ou invalide.');
                }
                if ($fileCount >= (int) $session['max_files']) {
                    throw new \DomainException('Le nombre maximal de fichiers de la session est atteint.');
                }
                if ($byteCount + $fileSize > (int) $session['max_bytes']) {
                    throw new \DomainException('Le quota en octets de la session serait depasse.');
                }

                $stored = $this->storage->store($category, $file);
                $storedPath = $stored['path'];
                $location = $this->parseStorageUri($stored['path']);
                $assetId = Uuid::v7()->toRfc4122();

                $this->db->executeStatement(
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
                        SQL,
                    [
                        'publicId' => $assetId,
                        'sessionId' => (int) $session['id'],
                        'category' => $category,
                        'bucket' => $location['bucket'],
                        'objectKey' => $location['objectKey'],
                        'mimeType' => $stored['mimeType'],
                        'extension' => $stored['extension'],
                        'sizeBytes' => $stored['size'],
                        'checksum' => $stored['checksumSha256'],
                    ],
                );
                $this->recordOutbox(
                    'uploaded_asset',
                    $assetId,
                    'provider.uploaded_asset.stored',
                    [
                        'assetId' => $assetId,
                        'sessionId' => $sessionPublicId,
                        'category' => $category,
                        'mimeType' => $stored['mimeType'],
                        'size' => $stored['size'],
                        'checksumSha256' => $stored['checksumSha256'],
                    ],
                );

                $newFileCount = $fileCount + 1;
                $newByteCount = $byteCount + $stored['size'];
                if ($newFileCount >= (int) $session['max_files'] || $newByteCount >= (int) $session['max_bytes']) {
                    $this->db->executeStatement(
                        "UPDATE upload_session SET status = 'COMPLETED', updated_at = now() WHERE id = :sessionId",
                        ['sessionId' => (int) $session['id']],
                    );
                }

                return [
                    'assetId' => $assetId,
                    'sessionId' => $sessionPublicId,
                    'category' => $category,
                    'mimeType' => $stored['mimeType'],
                    'extension' => $stored['extension'],
                    'size' => $stored['size'],
                    'checksumSha256' => $stored['checksumSha256'],
                    'validationStatus' => 'VALID',
                    'consumed' => false,
                ];
            });
        } catch (\Throwable $exception) {
            if ($exception instanceof \DomainException && $exception->getCode() === 410) {
                $this->db->executeStatement(
                    <<<'SQL'
                        UPDATE upload_session
                        SET status = 'EXPIRED', updated_at = now()
                        WHERE public_id = :publicId
                          AND user_id = :userId
                          AND status = 'OPEN'
                        SQL,
                    ['publicId' => $sessionPublicId, 'userId' => $userId],
                );
            }
            if ($storedPath !== null) {
                try {
                    $this->storage->deleteIfStored($storedPath);
                } catch (\Throwable) {
                    // The original persistence error remains the actionable failure.
                }
            }

            throw $exception;
        }
    }

    public function consume(int $userId, string $sessionPublicId, string $assetPublicId): void
    {
        $updated = $this->db->executeStatement(
            <<<'SQL'
                UPDATE uploaded_asset asset
                SET consumed_at = now()
                FROM upload_session session
                WHERE asset.session_id = session.id
                  AND asset.public_id = :assetPublicId
                  AND session.public_id = :sessionPublicId
                  AND session.user_id = :userId
                  AND asset.validation_status = 'VALID'
                  AND asset.consumed_at IS NULL
                SQL,
            [
                'assetPublicId' => $assetPublicId,
                'sessionPublicId' => $sessionPublicId,
                'userId' => $userId,
            ],
        );

        if ($updated !== 1) {
            throw new \DomainException('Asset introuvable, invalide ou deja consomme.');
        }
    }

    /**
     * @param array<string, mixed> $session
     */
    private function assertOpenSession(array $session): void
    {
        if ((string) $session['status'] !== 'OPEN') {
            throw new \DomainException('La session d upload n est plus ouverte.');
        }

        $expiresAt = new \DateTimeImmutable((string) $session['expires_at']);
        if ($expiresAt <= new \DateTimeImmutable()) {
            throw new \DomainException('La session d upload a expire.', 410);
        }
    }

    /**
     * @return list<string>
     */
    private function decodeStringList(mixed $value): array
    {
        $decoded = is_array($value)
            ? $value
            : json_decode((string) $value, true, flags: JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            throw new \UnexpectedValueException('Categories de session invalides.');
        }

        return array_values(array_filter($decoded, 'is_string'));
    }

    private function encodeJson(mixed $value): string
    {
        return (string) json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
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

    /**
     * @param array<string, mixed> $payload
     */
    private function recordOutbox(
        string $aggregateType,
        string $aggregateId,
        string $eventName,
        array $payload,
    ): void {
        $this->db->executeStatement(
            <<<'SQL'
                INSERT INTO outbox_event (
                    id,
                    aggregate_type,
                    aggregate_id,
                    event_name,
                    payload,
                    occurred_at
                )
                VALUES (
                    :id,
                    :aggregateType,
                    :aggregateId,
                    :eventName,
                    CAST(:payload AS jsonb),
                    now()
                )
                SQL,
            [
                'id' => Uuid::v7()->toRfc4122(),
                'aggregateType' => $aggregateType,
                'aggregateId' => $aggregateId,
                'eventName' => $eventName,
                'payload' => $this->encodeJson($payload),
            ],
        );
    }
}
