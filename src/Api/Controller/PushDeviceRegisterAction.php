<?php

declare(strict_types=1);

namespace App\Api\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final readonly class PushDeviceRegisterAction
{
    private const PLATFORMS = ['android', 'ios', 'web'];

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

        $payload = json_decode($request->getContent(), true);
        $token = is_array($payload) && is_string($payload['token'] ?? null) ? trim($payload['token']) : '';
        $platform = is_array($payload) && is_string($payload['platform'] ?? null) ? strtolower($payload['platform']) : '';
        $deviceId = is_array($payload) && is_string($payload['deviceId'] ?? null) ? trim($payload['deviceId']) : null;

        if ($token === '' || strlen($token) > 4096 || !in_array($platform, self::PLATFORMS, true)) {
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
                'userId' => $user->getId(),
                'tokenHash' => hash('sha256', $token),
                'token' => $token,
                'platform' => $platform,
                'deviceId' => $deviceId === '' ? null : $deviceId,
            ],
        );

        return new JsonResponse(null, 204);
    }
}
