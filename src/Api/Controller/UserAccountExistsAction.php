<?php

namespace App\Api\Controller;

use App\Service\UserAccountService;
use App\Util\PhoneNumberNormalizer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class UserAccountExistsAction
{
    public function __construct(private UserAccountService $userAccountService)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['message' => 'Invalid JSON body'], 400);
        }

        $phone = $payload['phone'] ?? null;
        if (!is_string($phone) || trim($phone) === '') {
            return new JsonResponse(['message' => 'phone est requis'], 400);
        }

        $phone = PhoneNumberNormalizer::normalize($phone);
        if ($phone === '') {
            return new JsonResponse(['message' => 'phone est invalide'], 400);
        }

        return new JsonResponse($this->userAccountService->verifiedUserExists($phone));
    }
}
