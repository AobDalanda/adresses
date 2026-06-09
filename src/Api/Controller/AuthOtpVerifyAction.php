<?php

namespace App\Api\Controller;

use App\Service\JwtAuthService;
use App\Service\OtpService;
use App\Service\UserAccountAssetUrlResolver;
use App\Service\UserAccountService;
use App\Util\PhoneNumberNormalizer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class AuthOtpVerifyAction
{
    public function __construct(
        private OtpService $otpService,
        private UserAccountService $userAccountService,
        private JwtAuthService $jwt,
        private UserAccountAssetUrlResolver $assetUrlResolver
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['message' => 'Invalid JSON body'], 400);
        }

        $phone = $payload['phone'] ?? null;
        $otp = $payload['otp'] ?? null;
        if (!is_string($phone) || $phone === '' || !is_string($otp) || $otp === '') {
            return new JsonResponse(['message' => 'phone et otp sont requis'], 400);
        }

        $phone = PhoneNumberNormalizer::normalize($phone);
        if ($phone === '') {
            return new JsonResponse(['message' => 'phone est invalide'], 400);
        }

        if (!$this->otpService->verifyOtp($phone, $otp)) {
            return new JsonResponse(['message' => 'OTP invalide'], 401);
        }

        $user = $this->userAccountService->findVerifiedUserByPhone($phone);
        if (!$user) {
            return new JsonResponse(['message' => 'USER_NOT_FOUND'], 404);
        }

        $tokenVersion = $this->userAccountService->rotateTokenVersion((int) $user['id']);
        $token = $this->jwt->issueToken([
            'sub' => $phone,
            'typ' => 'mobile',
            'uid' => $user['id'],
            'tv' => $tokenVersion,
        ]);

        return new JsonResponse([
            'token' => $token,
            'refreshToken' => $this->jwt->issueToken([
                'sub' => $phone,
                'typ' => 'mobile_refresh',
                'uid' => $user['id'],
                'tv' => $tokenVersion,
            ], JwtAuthService::REFRESH_TOKEN_TTL_SECONDS),
            'user' => $this->userPayload($user),
        ]);
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function userPayload(array $user): array
    {
        $payload = $this->assetUrlResolver->enrich($user);
        $payload['email'] = isset($user['email']) && is_string($user['email']) && $user['email'] !== ''
            ? $user['email']
            : null;

        return $payload;
    }
}
