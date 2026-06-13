<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ProviderAutomaticCheckService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class ProviderAutomaticCheckServiceTest extends TestCase
{
    public function testChecksProduceWarningsAndMoveApplicationToHumanReview(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::exactly(2))
            ->method('fetchAssociative')
            ->willReturnOnConsecutiveCalls(
                [
                    'id' => '10',
                    'public_id' => '01975aa9-df9c-7b25-b797-6b1ca912e68e',
                    'status' => 'SUBMITTED',
                    'current_revision_id' => '20',
                    'activities' => '["PEOPLE_TRANSPORT"]',
                ],
                ['document_count' => '1', 'invalid_count' => '0'],
            );
        $db->expects(self::once())
            ->method('fetchAllAssociative')
            ->willReturn([['document_type' => 'IDENTITY_FRONT']]);

        $statements = [];
        $db->expects(self::exactly(8))
            ->method('executeStatement')
            ->willReturnCallback(static function (string $sql, array $parameters = []) use (&$statements): int {
                $statements[] = [$sql, $parameters];

                return 1;
            });

        (new ProviderAutomaticCheckService($db))->run(
            '01975aa9-df9c-7b25-b797-6b1ca912e68e',
            Uuid::v7()->toRfc4122(),
        );

        self::assertSame('AUTO_CHECK', $statements[0][1]['newStatus']);
        self::assertSame('REQUIRED_DOCUMENTS', $statements[3][1]['checkType']);
        self::assertSame('WARNING', $statements[3][1]['status']);
        self::assertSame('DOCUMENT_INTEGRITY', $statements[4][1]['checkType']);
        self::assertSame('PASSED', $statements[4][1]['status']);
        self::assertSame('UNDER_REVIEW', $statements[5][1]['newStatus']);
    }

    public function testAlreadyReviewedApplicationIsIdempotentlyIgnored(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())
            ->method('fetchAssociative')
            ->willReturn([
                'id' => '10',
                'public_id' => '01975aa9-df9c-7b25-b797-6b1ca912e68e',
                'status' => 'UNDER_REVIEW',
                'current_revision_id' => '20',
                'activities' => '["DELIVERY"]',
            ]);
        $db->expects(self::never())->method('executeStatement');

        (new ProviderAutomaticCheckService($db))->run(
            '01975aa9-df9c-7b25-b797-6b1ca912e68e',
            Uuid::v7()->toRfc4122(),
        );
    }

    public function testTerminalFailurePersistsErrorChecksAndRoutesToHumanReview(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())
            ->method('fetchAssociative')
            ->willReturn([
                'id' => '10',
                'status' => 'SUBMITTED',
                'current_revision_id' => '20',
            ]);
        $statements = [];
        $db->expects(self::exactly(5))
            ->method('executeStatement')
            ->willReturnCallback(static function (string $sql, array $parameters = []) use (&$statements): int {
                $statements[] = [$sql, $parameters];

                return 1;
            });

        (new ProviderAutomaticCheckService($db))->fail(
            '01975aa9-df9c-7b25-b797-6b1ca912e68e',
            Uuid::v7()->toRfc4122(),
            'provider unavailable',
        );

        self::assertSame('ERROR', $statements[0][1]['status']);
        self::assertSame('ERROR', $statements[1][1]['status']);
        self::assertSame('UNDER_REVIEW', $statements[2][1]['newStatus']);
        self::assertSame('provider.automatic_check.failed', $statements[4][1]['eventName']);
    }
}
