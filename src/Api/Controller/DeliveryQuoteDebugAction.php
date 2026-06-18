<?php

namespace App\Api\Controller;

use App\Service\JwtAuthService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class DeliveryQuoteDebugAction
{
    public function __construct(private JwtAuthService $jwt)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $auth = $this->jwt->decodeFromRequest($request);
        if (!$auth || ($auth['typ'] ?? null) !== 'mobile' || !isset($auth['uid'])) {
            return new JsonResponse(['message' => 'Unauthorized'], 401);
        }

        $rawBody = $request->getContent();
        $decodedBody = json_decode($rawBody, true);

        return new JsonResponse([
            'route' => (string) $request->attributes->get('_route'),
            'method' => $request->getMethod(),
            'pathInfo' => $request->getPathInfo(),
            'requestUri' => $request->getRequestUri(),
            'contentType' => $request->headers->get('Content-Type'),
            'hasAuthorization' => $request->headers->has('Authorization'),
            'bodyRaw' => $rawBody,
            'bodyJson' => is_array($decodedBody) ? $decodedBody : null,
            'fields' => is_array($decodedBody) ? [
                'departure' => $decodedBody['departure'] ?? null,
                'destination' => $decodedBody['destination'] ?? null,
                'serviceType' => $decodedBody['serviceType'] ?? null,
                'vehicleType' => $decodedBody['vehicleType'] ?? null,
            ] : null,
            'authenticatedUserId' => (int) $auth['uid'],
        ]);
    }
}
