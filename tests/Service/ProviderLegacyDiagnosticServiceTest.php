<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ProviderLegacyDiagnosticService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\TestCase;

final class ProviderLegacyDiagnosticServiceTest extends TestCase
{
    public function testDiagnoseAggregatesReadOnlyLegacyIssues(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())
            ->method('fetchAssociative')
            ->willReturn([
                'profile_count' => '3',
                'application_count' => '4',
            ]);
        $db->expects(self::exactly(8))
            ->method('fetchAllAssociative')
            ->willReturnCallback(static function (
                string $sql,
                array $parameters = [],
                array $types = []
            ): array {
                self::assertSame(['limit' => 10], $parameters);
                self::assertSame(['limit' => ParameterType::INTEGER], $types);

                if (str_contains($sql, 'application.user_id IS NULL')) {
                    return [[
                        'application_id' => '12',
                        'application_phone' => '620000000',
                        'application_status' => 'PENDING',
                        'issue_total' => '1',
                    ]];
                }

                if (str_contains($sql, 'lower(application.status)')) {
                    return [[
                        'profile_id' => '8',
                        'application_id' => '14',
                        'user_id' => '42',
                        'profile_status' => 'approved',
                        'application_status' => 'PENDING',
                        'issue_total' => '1',
                    ]];
                }

                return [];
            });
        $db->expects(self::never())->method('executeStatement');

        $report = (new ProviderLegacyDiagnosticService($db))->diagnose(10);

        self::assertSame(['profiles' => 3, 'applications' => 4], $report['totals']);
        self::assertSame(2, $report['issueCount']);
        self::assertSame(1, $report['byType']['APPLICATION_WITHOUT_USER']);
        self::assertSame(1, $report['byType']['STATUS_MISMATCH']);
        self::assertSame([
            'applicationId' => 12,
            'applicationPhone' => '620000000',
            'applicationStatus' => 'PENDING',
        ], $report['issues'][0]['context']);
        self::assertSame('critical', $report['issues'][1]['severity']);
        self::assertSame(42, $report['issues'][1]['context']['userId']);
    }

    public function testDiagnoseRejectsUnsafeLimit(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('fetchAssociative');

        $this->expectException(\InvalidArgumentException::class);
        (new ProviderLegacyDiagnosticService($db))->diagnose(0);
    }
}
