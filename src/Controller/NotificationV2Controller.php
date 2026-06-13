<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\RequestIdentityResolver;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v2/notifications')]
final class NotificationV2Controller
{
    public function __construct(
        private readonly Connection $db,
        private readonly RequestIdentityResolver $identities,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $userId = $this->userId($request);
        if ($userId === null) {
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
            ['userId' => $userId],
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

    #[Route('/devices', methods: ['PUT'])]
    public function registerDevice(Request $request): JsonResponse
    {
        $userId = $this->userId($request);
        if ($userId === null) {
            return new JsonResponse(['message' => 'Unauthorized'], 401);
        }

        $payload = json_decode($request->getContent(), true);
        $token = is_array($payload) && is_string($payload['token'] ?? null) ? trim($payload['token']) : '';
        $platform = is_array($payload) && is_string($payload['platform'] ?? null) ? strtolower($payload['platform']) : '';
        $deviceId = is_array($payload) && is_string($payload['deviceId'] ?? null) ? trim($payload['deviceId']) : null;
        if ($token === '' || strlen($token) > 4096 || !in_array($platform, ['android', 'ios', 'web'], true)) {
            return new JsonResponse(['message' => 'Jeton ou plateforme invalide'], 422);
        }
        if ($deviceId !== null && strlen($deviceId) > 160) {
            return new JsonResponse(['message' => 'Identifiant de terminal invalide'], 422);
        }

        $this->db->executeStatement(
            <<<'SQL'
                INSERT INTO user_push_device (
                    user_id, token_hash, token, platform, device_id, enabled,
                    last_seen_at, created_at, updated_at
                )
                VALUES (
                    :userId, :tokenHash, :token, :platform, :deviceId, TRUE,
                    now(), now(), now()
                )
                ON CONFLICT (token_hash) DO UPDATE SET
                    user_id = EXCLUDED.user_id,
                    token = EXCLUDED.token,
                    platform = EXCLUDED.platform,
                    device_id = EXCLUDED.device_id,
                    enabled = TRUE,
                    last_seen_at = now(),
                    updated_at = now()
                SQL,
            [
                'userId' => $userId,
                'tokenHash' => hash('sha256', $token),
                'token' => $token,
                'platform' => $platform,
                'deviceId' => $deviceId === '' ? null : $deviceId,
            ],
        );

        return new JsonResponse(null, 204);
    }

    #[Route('/{id}/read', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['PUT'])]
    public function markRead(string $id, Request $request): JsonResponse
    {
        $userId = $this->userId($request);
        if ($userId === null) {
            return new JsonResponse(['message' => 'Unauthorized'], 401);
        }

        $updated = $this->db->executeStatement(
            'UPDATE user_notification SET read_at = COALESCE(read_at, now()) WHERE id = :id AND user_id = :userId',
            ['id' => $id, 'userId' => $userId],
        );

        return $updated === 1
            ? new JsonResponse(null, 204)
            : new JsonResponse(['message' => 'Notification introuvable'], 404);
    }

    private function userId(Request $request): ?int
    {
        return $this->identities->resolveMobile($request)?->user->getId();
    }
}
