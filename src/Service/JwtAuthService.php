<?php

namespace App\Service;

use Lexik\Bundle\JWTAuthenticationBundle\Encoder\JWTEncoderInterface;
use Symfony\Component\HttpFoundation\Request;

class JwtAuthService
{
    public const REFRESH_TOKEN_TTL_SECONDS = 2_592_000;

    public function __construct(
        private JWTEncoderInterface $encoder,
        private UserAccountService $users,
        private int $tokenTtlSeconds = 3600
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function issueToken(array $payload, ?int $ttlSeconds = null): string
    {
        $now = time();
        $payload['iat'] = $now;
        $payload['exp'] = $now + ($ttlSeconds ?? $this->tokenTtlSeconds);

        return $this->encoder->encode($payload);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function decodeFromRequest(Request $request): ?array
    {
        $authHeader = $request->headers->get('Authorization');
        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return null;
        }

        $token = substr($authHeader, 7);
        if ($token === '') {
            return null;
        }

        return $this->decodeToken($token);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function decodeToken(string $token): ?array
    {
        try {
            $payload = $this->encoder->decode($token);
        } catch (\Throwable) {
            return null;
        }

        if (!isset($payload['exp']) || (int) $payload['exp'] < time()) {
            return null;
        }

        if (in_array($payload['typ'] ?? null, ['mobile', 'mobile_refresh'], true)) {
            if (!isset($payload['uid'], $payload['tv'])) {
                return null;
            }

            $currentTokenVersion = $this->users->findTokenVersionById((int) $payload['uid']);
            if ($currentTokenVersion === null || $currentTokenVersion !== (int) $payload['tv']) {
                return null;
            }
        }

        return $payload;
    }
}
