<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AccountDocumentStorage;
use App\Service\ProfilePhotoStorage;
use App\Service\ProviderUploadSessionService;
use App\Service\UploadedFileSecurityValidator;
use App\Service\UploadStorageService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

final class ProviderUploadSessionServiceTest extends TestCase
{
    public function testCreatePersistsAnOwnedShortLivedSession(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('transactional')
            ->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $parameters = null;
        $db->expects(self::exactly(2))
            ->method('executeStatement')
            ->willReturnCallback(static function (string $sql, array $values) use (&$parameters): int {
                if (str_contains($sql, 'INSERT INTO upload_session')) {
                    $parameters = $values;
                }

                return 1;
            });

        $service = new ProviderUploadSessionService($db, $this->storage(), 900);
        $result = $service->create(42, ['identity_document', 'vehicle_photo'], 4, 10_000_000);

        self::assertTrue(Uuid::isValid($result['sessionId']));
        self::assertSame('OPEN', $result['status']);
        self::assertSame(4, $result['maxFiles']);
        self::assertSame(42, $parameters['userId']);
        self::assertSame(
            ['identity_document', 'vehicle_photo'],
            json_decode($parameters['categories'], true, flags: JSON_THROW_ON_ERROR),
        );
    }

    public function testUploadStoresDetectedMetadataWithoutReturningStoragePath(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('transactional')
            ->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $db->expects(self::exactly(2))
            ->method('fetchAssociative')
            ->willReturnOnConsecutiveCalls(
                [
                    'id' => '7',
                    'allowed_categories' => '["identity_document"]',
                    'max_files' => '2',
                    'max_bytes' => '100000',
                    'status' => 'OPEN',
                    'expires_at' => (new \DateTimeImmutable('+10 minutes'))->format(\DateTimeInterface::ATOM),
                ],
                ['file_count' => '0', 'byte_count' => '0'],
            );
        $assetParameters = null;
        $db->expects(self::exactly(2))
            ->method('executeStatement')
            ->willReturnCallback(static function (string $sql, array $parameters) use (&$assetParameters): int {
                if (str_contains($sql, 'INSERT INTO uploaded_asset')) {
                    $assetParameters = $parameters;
                }

                return 1;
            });

        $file = $this->pngUpload();
        $service = new ProviderUploadSessionService($db, $this->storage(), 900);
        $result = $service->upload(42, Uuid::v7()->toRfc4122(), 'identity_document', $file);

        self::assertTrue(Uuid::isValid($result['assetId']));
        self::assertSame('image/png', $result['mimeType']);
        self::assertSame('png', $result['extension']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $result['checksumSha256']);
        self::assertArrayNotHasKey('path', $result);
        self::assertSame('private', $assetParameters['bucket']);
        self::assertSame('test.png', $assetParameters['objectKey']);
    }

    public function testConsumeIsAtomicAndRejectsAlreadyConsumedAsset(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::exactly(2))
            ->method('executeStatement')
            ->willReturnOnConsecutiveCalls(1, 0);
        $service = new ProviderUploadSessionService($db, $this->storage(), 900);
        $sessionId = Uuid::v7()->toRfc4122();
        $assetId = Uuid::v7()->toRfc4122();

        $service->consume(42, $sessionId, $assetId);

        $this->expectException(\DomainException::class);
        $service->consume(42, $sessionId, $assetId);
    }

    public function testExpiredSessionIsPersistentlyMarkedAfterTransactionRollback(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('transactional')
            ->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $db->expects(self::once())
            ->method('fetchAssociative')
            ->willReturn([
                'id' => '7',
                'allowed_categories' => '["identity_document"]',
                'max_files' => '2',
                'max_bytes' => '100000',
                'status' => 'OPEN',
                'expires_at' => (new \DateTimeImmutable('-1 minute'))->format(\DateTimeInterface::ATOM),
            ]);
        $db->expects(self::once())
            ->method('executeStatement')
            ->with(
                self::stringContains("status = 'EXPIRED'"),
                self::callback(static fn (array $parameters): bool => $parameters['userId'] === 42),
            )
            ->willReturn(1);

        $service = new ProviderUploadSessionService($db, $this->storage(), 900);

        try {
            $service->upload(42, Uuid::v7()->toRfc4122(), 'identity_document', $this->pngUpload());
            self::fail('An expired session must reject uploads.');
        } catch (\DomainException $exception) {
            self::assertSame(410, $exception->getCode());
        }
    }

    private function storage(): UploadStorageService
    {
        $documents = $this->createMock(AccountDocumentStorage::class);
        $documents->method('storeIdentityDocument')->willReturn('supabase://private/test.png');
        $photos = $this->createMock(ProfilePhotoStorage::class);

        return new UploadStorageService(
            $documents,
            $photos,
            new UploadedFileSecurityValidator(),
        );
    }

    private function pngUpload(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'provider-upload-');
        self::assertIsString($path);
        file_put_contents(
            $path,
            base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
                true,
            ),
        );

        return new UploadedFile($path, 'identity.png', 'image/png', UPLOAD_ERR_OK, true);
    }
}
