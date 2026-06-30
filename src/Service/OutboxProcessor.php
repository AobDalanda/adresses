<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use App\Service\Tracking\DeliveryLocationPublisher;
use Symfony\Component\Uid\Uuid;

class OutboxProcessor
{
    public function __construct(
        private readonly Connection $db,
        private readonly ProviderAutomaticCheckService $automaticChecks,
        private readonly ProviderNotificationService $notifications,
        private readonly DeliveryLocationPublisher $deliveryLocations,
    ) {
    }

    /**
     * @return array{processed: int, published: int, retried: int, failed: int}
     */
    public function process(int $limit = 50, int $maxAttempts = 5): array
    {
        if ($limit < 1 || $limit > 1000) {
            throw new \InvalidArgumentException('La limite doit etre comprise entre 1 et 1000.');
        }
        if ($maxAttempts < 1 || $maxAttempts > 20) {
            throw new \InvalidArgumentException('Le nombre maximal de tentatives doit etre compris entre 1 et 20.');
        }

        $report = ['processed' => 0, 'published' => 0, 'retried' => 0, 'failed' => 0];
        for ($index = 0; $index < $limit; ++$index) {
            $outcome = $this->processOne($maxAttempts);
            if ($outcome === null) {
                break;
            }

            ++$report['processed'];
            ++$report[$outcome];
        }

        return $report;
    }

    private function processOne(int $maxAttempts): ?string
    {
        $claimed = $this->claimOne();
        if ($claimed === null) {
            return null;
        }

        try {
            $this->db->transactional(function () use ($claimed): void {
                $this->dispatch($claimed);
                $updated = $this->db->executeStatement(
                    <<<'SQL'
                        UPDATE outbox_event
                        SET published_at = now(),
                            attempts = attempts + 1,
                            last_error = NULL,
                            next_attempt_at = NULL,
                            processing_at = NULL,
                            processing_token = NULL
                        WHERE id = :id
                          AND processing_token = :processingToken
                        SQL,
                    [
                        'id' => (string) $claimed['id'],
                        'processingToken' => (string) $claimed['processing_token'],
                    ],
                );
                if ($updated !== 1) {
                    throw new \RuntimeException('Le verrou outbox a ete perdu pendant le traitement.');
                }
            });

            return 'published';
        } catch (\Throwable $exception) {
            return $this->recordFailure($claimed, $exception, $maxAttempts);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function claimOne(): ?array
    {
        return $this->db->transactional(function (): ?array {
            $event = $this->db->fetchAssociative(
                <<<'SQL'
                    SELECT id, aggregate_type, aggregate_id, event_name, payload, attempts
                    FROM outbox_event
                    WHERE published_at IS NULL
                      AND failed_at IS NULL
                      AND (next_attempt_at IS NULL OR next_attempt_at <= now())
                      AND (
                          processing_token IS NULL
                          OR processing_at < now() - INTERVAL '15 minutes'
                      )
                    ORDER BY occurred_at, id
                    LIMIT 1
                    FOR UPDATE SKIP LOCKED
                    SQL,
            );
            if ($event === false) {
                return null;
            }

            $processingToken = Uuid::v7()->toRfc4122();
            $this->db->executeStatement(
                <<<'SQL'
                    UPDATE outbox_event
                    SET processing_at = now(),
                        processing_token = :processingToken
                    WHERE id = :id
                    SQL,
                ['id' => (string) $event['id'], 'processingToken' => $processingToken],
            );
            $event['processing_token'] = $processingToken;

            return $event;
        });
    }

    /**
     * @param array<string, mixed> $event
     */
    private function recordFailure(array $event, \Throwable $exception, int $maxAttempts): string
    {
        $attempts = (int) $event['attempts'] + 1;
        $error = mb_substr($exception->getMessage(), 0, 4000);
        if ($attempts >= $maxAttempts) {
            if ((string) $event['event_name'] === 'provider.application.submitted') {
                try {
                    $this->db->transactional(function () use ($event, $error): void {
                        $payload = is_array($event['payload'])
                            ? $event['payload']
                            : json_decode((string) $event['payload'], true, flags: JSON_THROW_ON_ERROR);
                        $applicationId = $payload['applicationId'] ?? $event['aggregate_id'];
                        if (is_string($applicationId) && $applicationId !== '') {
                            $this->automaticChecks->fail($applicationId, (string) $event['id'], $error);
                        }
                    });
                } catch (\Throwable) {
                    // The outbox event must still leave the processing state after terminal failure.
                }
            }

            return $this->db->transactional(function () use ($event, $attempts, $error): string {
                $this->db->executeStatement(
                    <<<'SQL'
                        UPDATE outbox_event
                        SET attempts = :attempts,
                            last_error = :lastError,
                            failed_at = now(),
                            next_attempt_at = NULL,
                            processing_at = NULL,
                            processing_token = NULL
                        WHERE id = :id
                          AND processing_token = :processingToken
                        SQL,
                    [
                        'id' => (string) $event['id'],
                        'attempts' => $attempts,
                        'lastError' => $error,
                        'processingToken' => (string) $event['processing_token'],
                    ],
                );

                return 'failed';
            });
        }

        return $this->db->transactional(function () use ($event, $attempts, $error): string {
            $delaySeconds = min(3600, 2 ** min($attempts, 10) * 15);
            $this->db->executeStatement(
                <<<'SQL'
                    UPDATE outbox_event
                    SET attempts = :attempts,
                        last_error = :lastError,
                        next_attempt_at = now() + CAST(:delay AS interval),
                        processing_at = NULL,
                        processing_token = NULL
                    WHERE id = :id
                      AND processing_token = :processingToken
                    SQL,
                [
                    'id' => (string) $event['id'],
                    'attempts' => $attempts,
                    'lastError' => $error,
                    'delay' => sprintf('%d seconds', $delaySeconds),
                    'processingToken' => (string) $event['processing_token'],
                ],
            );

            return 'retried';
        });
    }

    /**
     * @param array<string, mixed> $event
     */
    private function dispatch(array $event): void
    {
        $payload = is_array($event['payload'])
            ? $event['payload']
            : json_decode((string) $event['payload'], true, flags: JSON_THROW_ON_ERROR);
        $eventName = (string) $event['event_name'];

        if ($eventName === 'delivery.location.updated') {
            $this->deliveryLocations->publish($payload);

            return;
        }

        if ($eventName === 'provider.application.submitted') {
            $applicationId = $payload['applicationId'] ?? $event['aggregate_id'];
            if (!is_string($applicationId) || $applicationId === '') {
                throw new \UnexpectedValueException('Evenement de soumission sans applicationId.');
            }

            $this->automaticChecks->run($applicationId, (string) $event['id']);
        }

        $this->notifications->handle(
            (string) $event['id'],
            $eventName,
            (string) $event['aggregate_id'],
            $payload,
        );
    }
}
