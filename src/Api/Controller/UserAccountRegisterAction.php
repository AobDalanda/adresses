<?php

namespace App\Api\Controller;

use App\Service\OtpService;
use App\Service\UserAccountService;
use App\Util\PhoneNumberNormalizer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class UserAccountRegisterAction
{
    public function __construct(
        private UserAccountService $userAccountService,
        private OtpService $otpService
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['message' => 'Invalid JSON body'], 400);
        }

        $phone = $payload['phone'] ?? null;
        $fullName = $payload['fullName'] ?? $payload['name'] ?? null;

        if (!is_string($phone) || trim($phone) === '') {
            return new JsonResponse(['message' => 'phone est requis'], 400);
        }

        if (!is_string($fullName) || trim($fullName) === '') {
            return new JsonResponse(['message' => 'fullName est requis'], 400);
        }

        $phone = PhoneNumberNormalizer::normalize($phone);
        $fullName = trim(preg_replace('/\s+/', ' ', $fullName) ?? $fullName);

        if ($phone === '') {
            return new JsonResponse(['message' => 'phone est invalide'], 400);
        }

        if (strlen($phone) > 20) {
            return new JsonResponse(['message' => 'phone ne doit pas dépasser 20 caractères'], 400);
        }

        if (strlen($fullName) > 100) {
            return new JsonResponse(['message' => 'fullName ne doit pas dépasser 100 caractères'], 400);
        }

        if ($this->userAccountService->userExists($phone)) {
            return new JsonResponse(['message' => 'Un compte existe déjà pour ce numéro de téléphone'], 409);
        }

        $this->userAccountService->createPendingRegistration($phone, $fullName);

        try {
            $this->otpService->requestOtp($phone);
        } catch (\Throwable) {
            return new JsonResponse(['message' => 'Erreur lors de l’envoi OTP'], 500);
        }

        return new JsonResponse(['message' => 'OTP envoyé'], 202);
    }
}
