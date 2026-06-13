<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\DiagnoseProviderLegacyCommand;
use App\Service\ProviderLegacyDiagnosticService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class DiagnoseProviderLegacyCommandTest extends TestCase
{
    public function testJsonReportCanFailForCiWhenIssuesExist(): void
    {
        $diagnostic = $this->createMock(ProviderLegacyDiagnosticService::class);
        $diagnostic->expects(self::once())
            ->method('diagnose')
            ->with(5)
            ->willReturn([
                'totals' => ['profiles' => 1, 'applications' => 2],
                'issueCount' => 1,
                'byType' => ['APPLICATION_WITHOUT_USER' => 1],
                'issues' => [[
                    'type' => 'APPLICATION_WITHOUT_USER',
                    'severity' => 'critical',
                    'description' => 'Dossier driver sans utilisateur rattache.',
                    'context' => ['applicationId' => 12],
                ]],
            ]);

        $tester = new CommandTester(new DiagnoseProviderLegacyCommand($diagnostic));
        $status = $tester->execute([
            '--format' => 'json',
            '--limit' => '5',
            '--fail-on-issues' => true,
        ]);

        self::assertSame(Command::FAILURE, $status);
        $payload = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, $payload['issueCount']);
        self::assertSame(12, $payload['issues'][0]['context']['applicationId']);
    }

    public function testTableReportSucceedsWhenNoIssueExists(): void
    {
        $diagnostic = $this->createMock(ProviderLegacyDiagnosticService::class);
        $diagnostic->method('diagnose')->willReturn([
            'totals' => ['profiles' => 2, 'applications' => 2],
            'issueCount' => 0,
            'byType' => ['STATUS_MISMATCH' => 0],
            'issues' => [],
        ]);

        $tester = new CommandTester(new DiagnoseProviderLegacyCommand($diagnostic));
        $status = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('Diagnostic legacy Prestataire', $tester->getDisplay());
        self::assertStringContainsString('Aucune incoherence legacy detectee', $tester->getDisplay());
    }
}
