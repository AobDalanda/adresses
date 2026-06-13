<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\Controller\AuthenticatedUserResolver;
use App\Service\ProviderApplicationV2Service;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v2/provider/applications')]
final class ProviderApplicationV2Controller
{
    public function __construct(
        private readonly AuthenticatedUserResolver $users,
        private readonly ProviderApplicationV2Service $applications,
    ) {
    }

    #[Route('', name: 'app_v2_provider_application_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $user = $this->users->requireMobileUser($request);
        if ($user === null) {
            return new JsonResponse(['message' => 'Unauthorized'], 401);
        }

        $idempotencyKey = $this->idempotencyKey($request);
        if ($idempotencyKey === null) {
            return new JsonResponse(['message' => 'Idempotency-Key est requis'], 400);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['message' => 'Invalid JSON body'], 400);
        }
        $activities = $payload['activities'] ?? null;
        if (!is_array($activities) || array_filter($activities, 'is_string') !== $activities) {
            return new JsonResponse(['message' => 'activities est requis'], 400);
        }

        try {
            $result = $this->applications->createDraft(
                (int) $user->getId(),
                array_values($activities),
                $idempotencyKey,
            );
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 400);
        } catch (\DomainException $exception) {
            return $this->domainError($exception);
        }

        return new JsonResponse($result['body'], $result['status']);
    }

    #[Route('/{applicationId}/submit', name: 'app_v2_provider_application_submit', methods: ['POST'])]
    public function submit(string $applicationId, Request $request): JsonResponse
    {
        $user = $this->users->requireMobileUser($request);
        if ($user === null) {
            return new JsonResponse(['message' => 'Unauthorized'], 401);
        }

        $idempotencyKey = $this->idempotencyKey($request);
        if ($idempotencyKey === null) {
            return new JsonResponse(['message' => 'Idempotency-Key est requis'], 400);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['message' => 'Invalid JSON body'], 400);
        }
        if (!isset($payload['revision']) || !is_int($payload['revision']) || $payload['revision'] < 1) {
            return new JsonResponse(['message' => 'revision doit etre un entier positif'], 400);
        }
        $documents = $payload['documentAssetIds'] ?? null;
        if (!is_array($documents)) {
            return new JsonResponse(['message' => 'documentAssetIds est requis'], 400);
        }

        try {
            $result = $this->applications->submit(
                (int) $user->getId(),
                $applicationId,
                $payload['revision'],
                $documents,
                $idempotencyKey,
            );
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 400);
        } catch (\DomainException $exception) {
            return $this->domainError($exception);
        }

        return new JsonResponse($result['body'], $result['status']);
    }

    private function idempotencyKey(Request $request): ?string
    {
        $key = $request->headers->get('Idempotency-Key');
        if (!is_string($key)) {
            return null;
        }

        $key = trim($key);

        return $key !== '' && strlen($key) <= 120 ? $key : null;
    }

    private function domainError(\DomainException $exception): JsonResponse
    {
        $status = in_array($exception->getCode(), [403, 404, 409, 422], true)
            ? $exception->getCode()
            : 409;

        return new JsonResponse(['message' => $exception->getMessage()], $status);
    }
}
