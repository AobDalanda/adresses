<?php

namespace App\Api\Controller;

use App\Service\JwtAuthService;
use App\Service\OtpService;
use App\Service\UserAccountService;
use App\Util\PhoneNumberNormalizer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class AuthOtpVerifyAction
{
    public function __construct(
        private OtpService $otpService,
        private UserAccountService $userAccountService,
        private JwtAuthService $jwt
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
        $name = $payload['name'] ?? null;
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

        $resolvedName = is_string($name) && trim($name) !== '' ? trim($name) : null;
        $registration = $this->userAccountService->findPendingRegistration($phone);
        if ($resolvedName === null && $registration) {
            $resolvedName = $registration['fullName'];
        }

        $userId = $this->userAccountService->ensureUserAccount(
            $phone,
            $resolvedName
        );

        if ($registration) {
            $this->userAccountService->markPendingRegistrationVerified($registration['id']);
        }

        $token = $this->jwt->issueToken([
            'sub' => $phone,
            'typ' => 'mobile',
            'uid' => $userId,
        ]);

        return new JsonResponse([
            'token' => $token,
        ]);
    }
}
