<?php

declare(strict_types=1);

namespace App\Api\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final readonly class NotificationListAction
{
    public function __construct(
        private Connection $db,
        private AuthenticatedUserResolver $users,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->users->requireMobileUser($request);
        if ($user === null) {
            return new JsonResponse(['message' => 'Unauthorized'], 401);
        }

        $rows = $this->db->fetchAllAssociative(
            <<<'SQL'
                SELECT id, type, title, body, data, read_at, created_at
                FROM user_notification
                WHERE user_id = :userId
                ORDER BY created_at DESC, id DESC
                LIMIT 100
                SQL,
            ['userId' => $user->getId()],
        );

        return new JsonResponse(['notifications' => array_map(
            static fn (array $row): array => [
                'id' => $row['id'],
                'type' => $row['type'],
                'title' => $row['title'],
                'body' => $row['body'],
                'data' => is_array($row['data']) ? $row['data'] : json_decode((string) $row['data'], true),
                'readAt' => $row['read_at'],
                'createdAt' => $row['created_at'],
            ],
            $rows,
        )]);
    }
}
