<?php

namespace App\Api\Controller;

use App\Service\JwtAuthService;
use App\Service\Subscription\SubscriptionManager;
use App\Service\OtpService;
use App\Service\UserAccountAssetUrlResolver;
use App\Service\UserAccountService;
use App\Util\PhoneNumberNormalizer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class UserAccountRegisterVerifyAction
{
    public function __construct(
        private OtpService $otpService,
        private UserAccountService $userAccountService,
        private JwtAuthService $jwt,
        private UserAccountAssetUrlResolver $assetUrlResolver,
        private SubscriptionManager $subscriptions
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

        $user = $this->userAccountService->upsertUserAccount(
            $phone,
            $registration['fullName'],
            true,
            $registration['profilePhotoPath'] ?? null,
            $registration['accountType'] ?? 'client',
            $registration['identityDocumentPath'] ?? null,
            $registration['driverLicensePath'] ?? null,
            $registration['email'] ?? null,
            $registration['identityDocumentNumber'] ?? null
        );

        $this->userAccountService->markPendingRegistrationVerified($registration['id']);
        $this->subscriptions->initializeFreeSubscription((int) $user['id']);

        $tokenVersion = $this->userAccountService->rotateTokenVersion((int) $user['id']);
        $token = $this->jwt->issueToken([
            'sub' => $phone,
            'typ' => 'mobile',
            'uid' => $user['id'],
            'tv' => $tokenVersion,
        ]);

        return new JsonResponse([
            'token' => $token,
            'user' => $this->userPayload($user),
        ], 201);
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
        $payload['identityDocumentNumber'] = isset($user['identityDocumentNumber']) && is_string($user['identityDocumentNumber']) && $user['identityDocumentNumber'] !== ''
            ? $user['identityDocumentNumber']
            : null;

        return $payload;
    }
}
