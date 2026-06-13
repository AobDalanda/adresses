<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\BackfillProviderLegacyCommand;
use App\Service\ProviderLegacyBackfillService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class BackfillProviderLegacyCommandTest extends TestCase
{
    public function testDryRunJsonReportIsForwardedToTheService(): void
    {
        $service = $this->createMock(ProviderLegacyBackfillService::class);
        $service->expects(self::once())
            ->method('backfill')
            ->with(25, true)
            ->willReturn([
                'dryRun' => true,
                'candidates' => 3,
                'imported' => 0,
                'applications' => 3,
                'authorizations' => 3,
                'remaining' => 3,
                'skipped' => [
                    'accountTypeMismatch' => 0,
                    'multipleApplications' => 1,
                    'statusMismatch' => 0,
                    'activityMismatch' => 0,
                ],
            ]);

        $tester = new CommandTester(new BackfillProviderLegacyCommand($service));
        $status = $tester->execute([
            '--dry-run' => true,
            '--batch-size' => '25',
            '--format' => 'json',
        ]);

        self::assertSame(Command::SUCCESS, $status);
        $report = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($report['dryRun']);
        self::assertSame(3, $report['candidates']);
        self::assertSame(1, $report['skipped']['multipleApplications']);
    }

    public function testInvalidBatchSizeIsRejected(): void
    {
        $service = $this->createMock(ProviderLegacyBackfillService::class);
        $service->expects(self::never())->method('backfill');

        $tester = new CommandTester(new BackfillProviderLegacyCommand($service));

        self::assertSame(Command::INVALID, $tester->execute(['--batch-size' => '0']));
    }
}
