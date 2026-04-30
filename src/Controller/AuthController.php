<?php

namespace App\Controller;

use App\Service\JwtAuthService;
use App\Service\OtpService;
use App\Service\UserAccountService;
use App\Util\PhoneNumberNormalizer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/auth')]
class AuthController extends AbstractController
{
    public function __construct(
        private OtpService $otpService,
        private UserAccountService $userAccountService,
        private JwtAuthService $jwt
    ) {
    }

    #[Route('/otp/request', methods: ['POST'])]
    public function requestOtp(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['message' => 'Invalid JSON body'], 400);
        }

        $phone = $payload['phone'] ?? null;
        if (!is_string($phone) || $phone === '') {
            return $this->json(['message' => 'phone est requis'], 400);
        }

        $phone = PhoneNumberNormalizer::normalize($phone);
        if ($phone === '') {
            return $this->json(['message' => 'phone est invalide'], 400);
        }

        try {
            $this->otpService->requestOtp($phone);
        } catch (\Throwable $e) {
            return $this->json(['message' => 'Erreur lors de l’envoi OTP'], 500);
        }

        return $this->json(['message' => 'OTP envoyé'], 200);
    }

    #[Route('/otp/verify', methods: ['POST'])]
    public function verifyOtp(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['message' => 'Invalid JSON body'], 400);
        }

        $phone = $payload['phone'] ?? null;
        $otp = $payload['otp'] ?? null;
        $name = $payload['name'] ?? null;
        if (!is_string($phone) || $phone === '' || !is_string($otp) || $otp === '') {
            return $this->json(['message' => 'phone et otp sont requis'], 400);
        }

        $phone = PhoneNumberNormalizer::normalize($phone);
        if ($phone === '') {
            return $this->json(['message' => 'phone est invalide'], 400);
        }

        if (!$this->otpService->verifyOtp($phone, $otp)) {
            return $this->json(['message' => 'OTP invalide'], 401);
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

        return $this->json([
            'token' => $token,
        ]);
    }
}
