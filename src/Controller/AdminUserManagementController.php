<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AdminUserManagementService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/admin/users')]
final class AdminUserManagementController extends AbstractController
{
    public function __construct(private readonly AdminUserManagementService $users)
    {
    }

    #[Route('', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        if (!$this->isGranted('ROLE_ADMIN')) {
            return $this->json(['message' => 'Forbidden'], 403);
        }

        try {
            $users = $this->users->list((string) $request->query->get('type', 'CLIENT'), $request->query->get('search'));
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['message' => $exception->getMessage()], 400);
        }

        return $this->json(['users' => $users, 'count' => count($users)]);
    }

    #[Route('', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        if (!$this->isGranted('ROLE_ADMIN')) {
            return $this->json(['message' => 'Forbidden'], 403);
        }

        try {
            return $this->json(['user' => $this->users->create($this->payload($request))], 201);
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['message' => $exception->getMessage()], 400);
        }
    }

    #[Route('/{id}', requirements: ['id' => '\\d+'], methods: ['PATCH'])]
    public function update(int $id, Request $request): JsonResponse
    {
        if (!$this->isGranted('ROLE_ADMIN')) {
            return $this->json(['message' => 'Forbidden'], 403);
        }
        try {
            $payload = $this->payload($request);
            $user = $this->users->update($id, (string) ($payload['type'] ?? ''), $payload);
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['message' => $exception->getMessage()], 400);
        }

        return $user === null ? $this->json(['message' => 'Utilisateur introuvable'], 404) : $this->json(['user' => $user]);
    }

    #[Route('/{id}/status', requirements: ['id' => '\\d+'], methods: ['PATCH'])]
    public function status(int $id, Request $request): JsonResponse
    {
        if (!$this->isGranted('ROLE_ADMIN')) {
            return $this->json(['message' => 'Forbidden'], 403);
        }
        try {
            $payload = $this->payload($request);
            if (!is_bool($payload['enabled'] ?? null)) {
                return $this->json(['message' => 'enabled doit être un booléen'], 400);
            }
            $user = $this->users->setEnabled($id, (string) ($payload['type'] ?? ''), $payload['enabled']);
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['message' => $exception->getMessage()], 400);
        }

        return $user === null ? $this->json(['message' => 'Utilisateur introuvable'], 404) : $this->json(['user' => $user]);
    }

    #[Route('/{id}', requirements: ['id' => '\\d+'], methods: ['DELETE'])]
    public function delete(int $id, Request $request): JsonResponse
    {
        if (!$this->isGranted('ROLE_ADMIN')) {
            return $this->json(['message' => 'Forbidden'], 403);
        }
        try {
            $deleted = $this->users->delete($id, (string) $request->query->get('type', ''));
        } catch (\DomainException $exception) {
            return $this->json(['message' => $exception->getMessage()], 409);
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['message' => $exception->getMessage()], 400);
        }

        return $deleted ? new JsonResponse(null, 204) : $this->json(['message' => 'Utilisateur introuvable'], 404);
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            throw new \InvalidArgumentException('Corps JSON invalide.');
        }

        return $payload;
    }
}
