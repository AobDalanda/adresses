<?php

namespace App\Service;

use Lexik\Bundle\JWTAuthenticationBundle\Encoder\JWTEncoderInterface;
use Symfony\Component\HttpFoundation\Request;

class JwtAuthService
{
    public function __construct(
        private JWTEncoderInterface $encoder,
        private int $tokenTtlSeconds = 3600
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function issueToken(array $payload): string
    {
        $now = time();
        $payload['iat'] = $now;
        $payload['exp'] = $now + $this->tokenTtlSeconds;

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

        return $payload;
    }
}
