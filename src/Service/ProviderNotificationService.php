<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

class ProviderNotificationService
{
    private const MESSAGES = [
        'provider.application.submitted' => [
            'type' => 'provider.application.submitted',
            'title' => 'Dossier recu',
            'body' => 'Votre dossier Prestataire a bien ete soumis.',
        ],
        'provider.automatic_check.completed' => [
            'type' => 'provider.application.under_review',
            'title' => 'Dossier en cours d examen',
            'body' => 'Les controles automatiques sont termines. Votre dossier est en cours d examen.',
        ],
        'provider.automatic_check.failed' => [
            'type' => 'provider.application.under_review',
            'title' => 'Dossier en cours d examen',
            'body' => 'Votre dossier a ete transmis pour un examen manuel.',
        ],
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly FcmPushClient $push,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(string $eventId, string $eventName, string $aggregateId, array $payload): void
    {
        $message = self::MESSAGES[$eventName] ?? null;
        if ($message === null) {
            return;
        }

        $applicationId = $payload['applicationId'] ?? $aggregateId;
        if (!is_string($applicationId) || $applicationId === '') {
            return;
        }

        $userId = $this->db->fetchOne(
            <<<'SQL'
                SELECT pp.user_id
                FROM provider_application pa
                JOIN provider_profile pp ON pp.id = pa.provider_profile_id
                WHERE pa.public_id = :applicationId
                SQL,
            ['applicationId' => $applicationId],
        );
        if ($userId === false) {
            return;
        }

        $notificationId = Uuid::v7()->toRfc4122();
        $inserted = $this->db->executeStatement(
            <<<'SQL'
                INSERT INTO user_notification (
                    id, user_id, source_event_id, type, title, body, data,
                    push_status, created_at
                )
                VALUES (
                    :id, :userId, :eventId, :type, :title, :body, CAST(:data AS jsonb),
                    'PENDING', now()
                )
                ON CONFLICT (source_event_id, user_id) DO NOTHING
                SQL,
            [
                'id' => $notificationId,
                'userId' => (int) $userId,
                'eventId' => $eventId,
                'type' => $message['type'],
                'title' => $message['title'],
                'body' => $message['body'],
                'data' => json_encode(
                    ['applicationId' => $applicationId],
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
                ),
            ],
        );
        if ($inserted === 0) {
            return;
        }

        $tokens = $this->db->fetchFirstColumn(
            'SELECT token FROM user_push_device WHERE user_id = :userId AND enabled = TRUE',
            ['userId' => (int) $userId],
        );
        if ($tokens === []) {
            $this->setPushResult($notificationId, 'SKIPPED', 0, null);

            return;
        }

        $sent = 0;
        $errors = [];
        foreach ($tokens as $token) {
            try {
                $this->push->send((string) $token, $message['title'], $message['body'], [
                    'type' => $message['type'],
                    'notificationId' => $notificationId,
                    'applicationId' => $applicationId,
                ]);
                ++$sent;
            } catch (\Throwable $exception) {
                $errors[] = $exception->getMessage();
                $this->logger->warning('Provider push notification failed.', [
                    'eventId' => $eventId,
                    'notificationId' => $notificationId,
                    'exception' => $exception,
                ]);
            }
        }

        $status = $sent === count($tokens) ? 'SENT' : ($sent > 0 ? 'PARTIAL' : 'FAILED');
        $error = $errors === [] ? null : mb_substr(implode(' | ', $errors), 0, 4000);
        $this->setPushResult($notificationId, $status, count($tokens), $error);
    }

    private function setPushResult(string $notificationId, string $status, int $attempts, ?string $error): void
    {
        $this->db->executeStatement(
            <<<'SQL'
                UPDATE user_notification
                SET push_status = :status,
                    push_attempts = :attempts,
                    push_last_error = :error
                WHERE id = :id
                SQL,
            compact('status', 'attempts', 'error') + ['id' => $notificationId],
        );
    }
}
