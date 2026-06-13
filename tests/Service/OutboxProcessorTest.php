<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\OutboxProcessor;
use App\Service\ProviderAutomaticCheckService;
use App\Service\ProviderNotificationService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class OutboxProcessorTest extends TestCase
{
    public function testSubmittedApplicationEventIsClaimedProcessedAndPublished(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('transactional')
            ->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $db->expects(self::exactly(2))
            ->method('fetchAssociative')
            ->willReturnOnConsecutiveCalls(
                [
                    'id' => '01975aa9-df9c-7b25-b797-6b1ca912e68e',
                    'aggregate_type' => 'provider_application',
                    'aggregate_id' => '01975aa9-df9c-7b25-b797-6b1ca912e68f',
                    'event_name' => 'provider.application.submitted',
                    'payload' => '{"applicationId":"01975aa9-df9c-7b25-b797-6b1ca912e68f"}',
                    'attempts' => '0',
                ],
                false,
            );
        $db->expects(self::exactly(2))->method('executeStatement')->willReturn(1);

        $checks = $this->createMock(ProviderAutomaticCheckService::class);
        $checks->expects(self::once())
            ->method('run')
            ->with(
                '01975aa9-df9c-7b25-b797-6b1ca912e68f',
                '01975aa9-df9c-7b25-b797-6b1ca912e68e',
            );
        $notifications = $this->createMock(ProviderNotificationService::class);
        $notifications->expects(self::once())
            ->method('handle')
            ->with(
                '01975aa9-df9c-7b25-b797-6b1ca912e68e',
                'provider.application.submitted',
                '01975aa9-df9c-7b25-b797-6b1ca912e68f',
                ['applicationId' => '01975aa9-df9c-7b25-b797-6b1ca912e68f'],
            );

        $report = (new OutboxProcessor($db, $checks, $notifications))->process(5, 3);

        self::assertSame([
            'processed' => 1,
            'published' => 1,
            'retried' => 0,
            'failed' => 0,
        ], $report);
    }

    public function testHandlerFailureSchedulesRetryWithoutPublishing(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('transactional')
            ->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $db->expects(self::exactly(2))
            ->method('fetchAssociative')
            ->willReturnOnConsecutiveCalls(
                [
                    'id' => '01975aa9-df9c-7b25-b797-6b1ca912e68e',
                    'aggregate_type' => 'provider_application',
                    'aggregate_id' => '01975aa9-df9c-7b25-b797-6b1ca912e68f',
                    'event_name' => 'provider.application.submitted',
                    'payload' => '{}',
                    'attempts' => '0',
                ],
                false,
            );
        $updates = [];
        $db->expects(self::exactly(2))
            ->method('executeStatement')
            ->willReturnCallback(static function (string $sql, array $parameters = []) use (&$updates): int {
                $updates[] = [$sql, $parameters];

                return 1;
            });

        $checks = $this->createMock(ProviderAutomaticCheckService::class);
        $checks->expects(self::once())->method('run')->willThrowException(new \RuntimeException('temporary'));
        $notifications = $this->createMock(ProviderNotificationService::class);
        $notifications->expects(self::never())->method('handle');

        $report = (new OutboxProcessor($db, $checks, $notifications))->process(5, 3);

        self::assertSame(1, $report['retried']);
        self::assertStringContainsString('next_attempt_at', $updates[1][0]);
        self::assertSame(1, $updates[1][1]['attempts']);
        self::assertSame('temporary', $updates[1][1]['lastError']);
    }
}
