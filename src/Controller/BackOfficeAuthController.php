<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\UserRoleProvider;
use App\Service\BackOfficeAccountService;
use App\Service\JwtAuthService;
use App\Service\OtpService;
use App\Service\UserAccountAssetUrlResolver;
use App\Service\UserAccountService;
use App\Util\PhoneNumberNormalizer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/back-office/auth')]
final class BackOfficeAuthController extends AbstractController
{
    private const BACK_OFFICE_ROLES = [
        'ROLE_ADMIN',
        'ROLE_PROVIDER_REVIEWER',
        'ROLE_PROVIDER_APPROVER',
        'ROLE_PROVIDER_SECURITY_ADMIN',
    ];

    public function __construct(
        private readonly OtpService $otpService,
        private readonly UserAccountService $users,
        private readonly BackOfficeAccountService $backOfficeAccounts,
        private readonly JwtAuthService $jwt,
        private readonly UserRoleProvider $roles,
        private readonly UserAccountAssetUrlResolver $assetUrlResolver
    ) {
    }

    #[Route('/otp/request', methods: ['POST'])]
    public function requestOtp(Request $request): JsonResponse
    {
        $phone = $this->phoneFromRequest($request);
        if ($phone instanceof JsonResponse) {
            return $phone;
        }

        $user = $this->users->findVerifiedUserByPhone($phone);
        if ($user === null) {
            return $this->json(['message' => 'USER_NOT_FOUND'], 404);
        }

        if (!$this->canAccessBackOffice($user)) {
            return $this->json(['message' => 'BACK_OFFICE_FORBIDDEN'], 403);
        }

        try {
            $this->otpService->requestOtp($phone, OtpService::PURPOSE_BACK_OFFICE_AUTH);
        } catch (\Throwable) {
            return $this->json(['message' => 'Erreur lors de l’envoi OTP'], 500);
        }

        return $this->json(['message' => 'OTP envoyé']);
    }

    #[Route('/otp/verify', methods: ['POST'])]
    public function verifyOtp(Request $request): JsonResponse
    {
        $payload = $this->jsonPayload($request);
        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        $phone = $payload['phone'] ?? null;
        $otp = $payload['otp'] ?? null;
        if (!is_string($phone) || $phone === '' || !is_string($otp) || $otp === '') {
            return $this->json(['message' => 'phone et otp sont requis'], 400);
        }

        $phone = PhoneNumberNormalizer::normalize($phone);
        if ($phone === '') {
            return $this->json(['message' => 'phone est invalide'], 400);
        }

        $user = $this->users->findVerifiedUserByPhone($phone);
        if ($user === null) {
            return $this->json(['message' => 'USER_NOT_FOUND'], 404);
        }

        if (!$this->canAccessBackOffice($user)) {
            return $this->json(['message' => 'BACK_OFFICE_FORBIDDEN'], 403);
        }

        $userPayload = $this->userPayload($user);

        if (!$this->otpService->verifyOtp($phone, $otp, OtpService::PURPOSE_BACK_OFFICE_AUTH)) {
            return $this->json(['message' => 'OTP invalide'], 401);
        }

        $tokenVersion = $this->backOfficeAccounts->rotateTokenVersion((int) $user['id']);
        $token = $this->jwt->issueToken([
            'sub' => $phone,
            'typ' => 'back_office',
            'aud' => 'bo.aldahim.com',
            'uid' => $user['id'],
            'tv' => $tokenVersion,
        ]);

        return $this->json([
            'token' => $token,
            'refreshToken' => $this->jwt->issueToken([
                'sub' => $phone,
                'typ' => 'back_office_refresh',
                'aud' => 'bo.aldahim.com',
                'uid' => $user['id'],
                'tv' => $tokenVersion,
            ], JwtAuthService::REFRESH_TOKEN_TTL_SECONDS),
            'user' => $userPayload,
        ]);
    }

    /**
     * @return array<string, mixed>|JsonResponse
     */
    private function jsonPayload(Request $request): array|JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['message' => 'Invalid JSON body'], 400);
        }

        return $payload;
    }

    private function phoneFromRequest(Request $request): string|JsonResponse
    {
        $payload = $this->jsonPayload($request);
        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        $phone = $payload['phone'] ?? null;
        if (!is_string($phone) || $phone === '') {
            return $this->json(['message' => 'phone est requis'], 400);
        }

        $phone = PhoneNumberNormalizer::normalize($phone);
        if ($phone === '') {
            return $this->json(['message' => 'phone est invalide'], 400);
        }

        return $phone;
    }

    /**
     * @param array<string, mixed> $user
     */
    private function canAccessBackOffice(array $user): bool
    {
        $userId = (int) $user['id'];
        if (!$this->backOfficeAccounts->isEnabled($userId)) {
            return false;
        }

        $roles = $this->roles->rolesForUser($userId);

        return array_intersect(self::BACK_OFFICE_ROLES, $roles) !== [];
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
