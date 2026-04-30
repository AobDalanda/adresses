<?php

namespace App\Api\Controller;

use App\Service\JwtAuthService;
use App\Service\OtpService;
use App\Service\UserAccountService;
use App\Util\PhoneNumberNormalizer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class UserAccountRegisterVerifyAction
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

        if (!is_string($phone) || trim($phone) === '' || !is_string($otp) || trim($otp) === '') {
            return new JsonResponse(['message' => 'phone et otp sont requis'], 400);
        }

        $phone = PhoneNumberNormalizer::normalize($phone);
        $otp = trim($otp);

        if ($phone === '') {
            return new JsonResponse(['message' => 'phone est invalide'], 400);
        }

        $registration = $this->userAccountService->findPendingRegistration($phone);
        if (!$registration) {
            return new JsonResponse(['message' => 'Pré-inscription introuvable'], 404);
        }

        if (!$this->otpService->verifyOtp($phone, $otp)) {
            return new JsonResponse(['message' => 'OTP invalide'], 401);
        }

        $user = $this->userAccountService->upsertUserAccount($phone, $registration['fullName'], true);

        $this->userAccountService->markPendingRegistrationVerified($registration['id']);

        $token = $this->jwt->issueToken([
            'sub' => $phone,
            'typ' => 'mobile',
            'uid' => $user['id'],
        ]);

        return new JsonResponse([
            'token' => $token,
            'user' => $user,
        ], 201);
    }
}
