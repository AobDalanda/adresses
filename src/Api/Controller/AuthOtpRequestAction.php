<?php

namespace App\Api\Controller;

use App\Service\OtpService;
use App\Util\PhoneNumberNormalizer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class AuthOtpRequestAction
{
    public function __construct(private OtpService $otpService)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['message' => 'Invalid JSON body'], 400);
        }

        $phone = $payload['phone'] ?? null;
        if (!is_string($phone) || $phone === '') {
            return new JsonResponse(['message' => 'phone est requis'], 400);
        }

        $phone = PhoneNumberNormalizer::normalize($phone);
        if ($phone === '') {
            return new JsonResponse(['message' => 'phone est invalide'], 400);
        }

        try {
            $this->otpService->requestOtp($phone);
        } catch (\Throwable) {
            return new JsonResponse(['message' => 'Erreur lors de l’envoi OTP'], 500);
        }

        return new JsonResponse(['message' => 'OTP envoyé'], 200);
    }
}
