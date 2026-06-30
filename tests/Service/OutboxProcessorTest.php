<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\OutboxProcessor;
use App\Service\ProviderAutomaticCheckService;
use App\Service\ProviderNotificationService;
use App\Service\Tracking\DeliveryLocationPublisher;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

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

        $report = (new OutboxProcessor($db, $checks, $notifications, $this->deliveryPublisher()))->process(5, 3);

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

        $report = (new OutboxProcessor($db, $checks, $notifications, $this->deliveryPublisher()))->process(5, 3);

        self::assertSame(1, $report['retried']);
        self::assertStringContainsString('next_attempt_at', $updates[1][0]);
        self::assertSame(1, $updates[1][1]['attempts']);
        self::assertSame('temporary', $updates[1][1]['lastError']);
    }

    public function testDeliveryLocationEventIsPublishedByWorker(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('transactional')->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $db->method('fetchAssociative')->willReturnOnConsecutiveCalls(
            [
                'id' => '01975aa9-df9c-7b25-b797-6b1ca912e68e',
                'aggregate_type' => 'delivery_order',
                'aggregate_id' => 'delivery-42',
                'event_name' => 'delivery.location.updated',
                'payload' => '{"deliveryId":"delivery-42","driverId":15}',
                'attempts' => '0',
            ],
            false,
        );
        $db->expects(self::exactly(2))->method('executeStatement')->willReturn(1);

        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::once())
            ->method('publish')
            ->with(self::callback(static fn (Update $update): bool =>
                $update->getTopics() === ['delivery/delivery-42/location']
            ));
        $checks = $this->createMock(ProviderAutomaticCheckService::class);
        $checks->expects(self::never())->method('run');
        $notifications = $this->createMock(ProviderNotificationService::class);
        $notifications->expects(self::never())->method('handle');

        $report = (new OutboxProcessor(
            $db,
            $checks,
            $notifications,
            new DeliveryLocationPublisher($hub),
        ))->process(5, 3);

        self::assertSame(1, $report['published']);
    }

    private function deliveryPublisher(): DeliveryLocationPublisher
    {
        return new DeliveryLocationPublisher($this->createMock(HubInterface::class));
    }
}
