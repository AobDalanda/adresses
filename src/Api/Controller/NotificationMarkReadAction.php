<?php

declare(strict_types=1);

namespace App\Api\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final readonly class NotificationMarkReadAction
{
    public function __construct(
        private Connection $db,
        private AuthenticatedUserResolver $users,
    ) {
    }

    public function __invoke(string $id, Request $request): JsonResponse
    {
        $user = $this->users->requireMobileUser($request);
        if ($user === null) {
            return new JsonResponse(['message' => 'Unauthorized'], 401);
        }

        $updated = $this->db->executeStatement(
            'UPDATE user_notification SET read_at = COALESCE(read_at, now()) WHERE id = :id AND user_id = :userId',
            ['id' => $id, 'userId' => $user->getId()],
        );

        return $updated === 1
            ? new JsonResponse(null, 204)
            : new JsonResponse(['message' => 'Notification introuvable'], 404);
    }
}
