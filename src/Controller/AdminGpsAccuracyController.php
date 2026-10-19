<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\AuthenticatedIdentity;
use App\Service\GpsAccuracyPolicyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/admin/settings/gps-accuracy')]
final class AdminGpsAccuracyController extends AbstractController
{
    public function __construct(private readonly GpsAccuracyPolicyService $policy)
    {
    }

    #[Route('', methods: ['GET'])]
    public function getPolicy(): JsonResponse
    {
        if (!$this->isGranted('ROLE_ADMIN')) {
            return $this->json(['message' => 'Forbidden'], 403);
        }

        return $this->json($this->policy->get());
    }

    #[Route('', methods: ['PATCH'])]
    public function updatePolicy(Request $request): JsonResponse
    {
        if (!$this->isGranted('ROLE_ADMIN')) {
            return $this->json(['message' => 'Forbidden'], 403);
        }

        $identity = $this->getUser();
        if (!$identity instanceof AuthenticatedIdentity) {
            return $this->json(['message' => 'Unauthorized'], 401);
        }

        try {
            $payload = $request->toArray();

            return $this->json($this->policy->update($payload['targetAccuracyMeters'] ?? null, $identity->getUserId()));
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['message' => $exception->getMessage()], 400);
        }
    }
}
