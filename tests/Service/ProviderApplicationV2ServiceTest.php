<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ProviderApplicationV2Service;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class ProviderApplicationV2ServiceTest extends TestCase
{
    public function testSubmitConsumesOwnedAssetsAndFreezesTheRevision(): void
    {
        $applicationId = Uuid::v7()->toRfc4122();
        $assetId = Uuid::v7()->toRfc4122();
        $db = $this->createMock(Connection::class);
        $db->method('transactional')
            ->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $db->expects(self::once())->method('executeQuery');
        $db->expects(self::exactly(2))
            ->method('fetchAssociative')
            ->willReturnOnConsecutiveCalls(
                false,
                [
                    'id' => '10',
                    'public_id' => $applicationId,
                    'status' => 'DRAFT',
                    'current_revision_id' => '20',
                    'lock_version' => '1',
                    'version' => '1',
                    'activities' => '["DELIVERY"]',
                ],
            );
        $db->expects(self::once())
            ->method('fetchAllAssociative')
            ->willReturn([[
                'id' => '30',
                'public_id' => $assetId,
                'category' => 'identity_document',
                'checksum_sha256' => str_repeat('a', 64),
            ]]);

        $statements = [];
        $db->expects(self::exactly(8))
            ->method('executeStatement')
            ->willReturnCallback(static function (
                string $sql,
                array $parameters = [],
                array $types = [],
            ) use (&$statements): int {
                $statements[] = [$sql, $parameters, $types];

                return str_contains($sql, 'UPDATE uploaded_asset') ? 1 : 1;
            });

        $result = (new ProviderApplicationV2Service($db))->submit(
            42,
            $applicationId,
            1,
            ['IDENTITY' => [$assetId]],
            'submit-1',
        );

        self::assertSame(202, $result['status']);
        self::assertSame('SUBMITTED', $result['body']['status']);
        self::assertStringContainsString('INSERT INTO provider_document', $statements[0][0]);
        self::assertSame('IDENTITY_FRONT', $statements[0][1]['documentType']);
        self::assertStringContainsString('UPDATE uploaded_asset', $statements[1][0]);
        self::assertStringContainsString('UPDATE provider_application_revision', $statements[3][0]);
        self::assertStringContainsString('INSERT INTO provider_decision_history', $statements[5][0]);
        self::assertStringContainsString('INSERT INTO outbox_event', $statements[6][0]);
        self::assertStringContainsString('INSERT INTO provider_idempotency_record', $statements[7][0]);
    }

    public function testSubmitReturnsStoredResponseForAnIdenticalIdempotentRetry(): void
    {
        $applicationId = Uuid::v7()->toRfc4122();
        $assetId = Uuid::v7()->toRfc4122();
        $documents = ['IDENTITY_FRONT' => [$assetId]];
        $request = [
            'applicationId' => $applicationId,
            'revision' => 1,
            'documentAssetIds' => $documents,
        ];
        $requestHash = hash('sha256', (string) json_encode(
            $request,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));

        $db = $this->createMock(Connection::class);
        $db->method('transactional')
            ->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $db->expects(self::once())->method('executeQuery');
        $db->expects(self::once())
            ->method('fetchAssociative')
            ->willReturn([
                'request_hash' => $requestHash,
                'response_status' => '202',
                'response_body' => json_encode([
                    'applicationId' => $applicationId,
                    'status' => 'SUBMITTED',
                ], JSON_THROW_ON_ERROR),
            ]);
        $db->expects(self::never())->method('fetchAllAssociative');
        $db->expects(self::never())->method('executeStatement');

        $result = (new ProviderApplicationV2Service($db))->submit(
            42,
            $applicationId,
            1,
            $documents,
            'submit-1',
        );

        self::assertSame(202, $result['status']);
        self::assertSame('SUBMITTED', $result['body']['status']);
    }

    public function testSubmitRejectsArbitraryDocumentReferencesBeforeDatabaseAccess(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('transactional');

        $this->expectException(\InvalidArgumentException::class);
        (new ProviderApplicationV2Service($db))->submit(
            42,
            Uuid::v7()->toRfc4122(),
            1,
            ['IDENTITY_FRONT' => ['supabase://bucket/arbitrary-path.jpg']],
            'submit-1',
        );
    }
}
