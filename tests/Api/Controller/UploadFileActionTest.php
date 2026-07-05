<?php

declare(strict_types=1);

namespace App\Tests\Api\Controller;

use App\Api\Controller\UploadFileAction;
use App\Service\AccountDocumentStorage;
use App\Service\DeliveryPackageUploadService;
use App\Service\JwtAuthService;
use App\Service\ProfilePhotoStorage;
use App\Service\UploadedFileSecurityValidator;
use App\Service\UploadStorageService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

final class UploadFileActionTest extends TestCase
{
    public function testPackagePhotoUploadReturnsAssetIdentifiers(): void
    {
        $jwt = $this->createMock(JwtAuthService::class);
        $logger = $this->createMock(LoggerInterface::class);
        $documents = $this->createMock(AccountDocumentStorage::class);
        $documents->expects(self::once())
            ->method('store')
            ->with(self::isInstanceOf(UploadedFile::class), 'package-photos')
            ->willReturn('supabase://private/package-photos/test.png');
        $uploads = new UploadStorageService(
            $documents,
            $this->createMock(ProfilePhotoStorage::class),
            new UploadedFileSecurityValidator(),
        );
        $db = $this->createMock(Connection::class);
        $deliveryUploads = new DeliveryPackageUploadService($db, $uploads);

        $jwt->expects(self::once())
            ->method('decodeFromRequest')
            ->willReturn(['typ' => 'mobile', 'uid' => 42]);

        $db->expects(self::once())
            ->method('transactional')
            ->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $db->expects(self::exactly(2))
            ->method('fetchOne')
            ->willReturnOnConsecutiveCalls(321, 987);

        $request = new Request(
            request: ['category' => 'package_photo'],
            files: ['file' => $this->pngUpload()]
        );
        $request->headers->set('Authorization', 'Bearer token');

        $response = (new UploadFileAction($jwt, $uploads, $deliveryUploads, $logger))->__invoke($request);
        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(201, $response->getStatusCode());
        self::assertTrue($payload['success']);
        self::assertSame('package_photo', $payload['category']);
        self::assertSame(987, $payload['assetId']);
        self::assertSame('VALID', $payload['validationStatus']);
        self::assertFalse($payload['consumed']);
        self::assertArrayHasKey('assetPublicId', $payload);
        self::assertArrayHasKey('sessionId', $payload);
    }

    public function testRecipientSignatureUploadUsesDedicatedCategory(): void
    {
        $jwt = $this->createMock(JwtAuthService::class);
        $logger = $this->createMock(LoggerInterface::class);
        $documents = $this->createMock(AccountDocumentStorage::class);
        $documents->expects(self::once())
            ->method('store')
            ->with(self::isInstanceOf(UploadedFile::class), 'delivery-proofs')
            ->willReturn('supabase://private/delivery-proofs/signature.png');
        $uploads = new UploadStorageService(
            $documents,
            $this->createMock(ProfilePhotoStorage::class),
            new UploadedFileSecurityValidator(),
        );
        $db = $this->createMock(Connection::class);
        $deliveryUploads = new DeliveryPackageUploadService($db, $uploads);

        $jwt->expects(self::once())
            ->method('decodeFromRequest')
            ->willReturn(['typ' => 'mobile', 'uid' => 42]);

        $db->expects(self::once())
            ->method('transactional')
            ->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $db->expects(self::exactly(2))
            ->method('fetchOne')
            ->willReturnOnConsecutiveCalls(321, 988);

        $request = new Request(
            request: ['category' => 'recipient_signature'],
            files: ['file' => $this->pngUpload()]
        );
        $request->headers->set('Authorization', 'Bearer token');

        $response = (new UploadFileAction($jwt, $uploads, $deliveryUploads, $logger))->__invoke($request);
        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('recipient_signature', $payload['category']);
        self::assertSame(988, $payload['assetId']);
    }

    private function pngUpload(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'package-photo-');
        self::assertIsString($path);
        file_put_contents(
            $path,
            base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
                true,
            ),
        );

        return new UploadedFile($path, 'package.png', 'image/png', UPLOAD_ERR_OK, true);
    }
}
