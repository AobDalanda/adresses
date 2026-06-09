<?php

declare(strict_types=1);

namespace App\Api\Controller;

use App\Service\JwtAuthService;
use App\Service\ProviderProfileService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class AdminProviderListAction
{
    public function __construct(
        private readonly JwtAuthService $jwt,
        private readonly ProviderProfileService $providers
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        if (!$this->isAdmin($request)) {
            return new JsonResponse(['message' => 'Forbidden'], 403);
        }

        $status = $request->query->get('status');
        if ($status !== null && (!is_string($status) || !in_array($status, ProviderProfileService::STATUSES, true))) {
            return new JsonResponse(['message' => 'status est invalide'], 400);
        }

        try {
            $canDeliver = $this->optionalBoolean($request, 'canDeliver');
            $canTransportPeople = $this->optionalBoolean($request, 'canTransportPeople');
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 400);
        }

        $providers = $this->providers->list($status, $canDeliver, $canTransportPeople);

        return new JsonResponse([
            'providers' => $providers,
            'count' => count($providers),
        ]);
    }

    private function isAdmin(Request $request): bool
    {
        $claims = $this->jwt->decodeFromRequest($request);
        $roles = is_array($claims) ? ($claims['roles'] ?? []) : [];

        return is_array($roles) && in_array('ROLE_ADMIN', $roles, true);
    }

    private function optionalBoolean(Request $request, string $name): ?bool
    {
        if (!$request->query->has($name)) {
            return null;
        }

        $value = filter_var($request->query->get($name), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($value === null) {
            throw new \InvalidArgumentException(sprintf('%s est invalide', $name));
        }

        return $value;
    }
}
