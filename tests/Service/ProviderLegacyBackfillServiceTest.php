<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ProviderLegacyBackfillService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class ProviderLegacyBackfillServiceTest extends TestCase
{
    public function testDryRunDoesNotOpenATransactionOrWrite(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())
            ->method('fetchAssociative')
            ->willReturn([
                'account_type_mismatch' => '0',
                'multiple_applications' => '0',
                'status_mismatch' => '0',
                'activity_mismatch' => '0',
            ]);
        $db->expects(self::once())
            ->method('fetchAllAssociative')
            ->willReturn([['profile_id' => '10'], ['profile_id' => '11']]);
        $db->expects(self::once())
            ->method('fetchOne')
            ->willReturn('2');
        $db->expects(self::never())->method('transactional');
        $db->expects(self::never())->method('executeStatement');

        $report = (new ProviderLegacyBackfillService($db))->backfill(50, true);

        self::assertSame(2, $report['candidates']);
        self::assertSame(0, $report['imported']);
        self::assertSame(2, $report['applications']);
        self::assertSame(2, $report['remaining']);
    }

    public function testUnsafeBatchSizeIsRejectedBeforeReadingTheDatabase(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('fetchAllAssociative');

        $this->expectException(\InvalidArgumentException::class);
        (new ProviderLegacyBackfillService($db))->backfill(1001);
    }
}
