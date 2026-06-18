<?php

namespace App\Api\Controller;

use App\Service\DeliveryQuoteService;
use App\Service\JwtAuthService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class DeliveryQuoteAction
{
    public function __construct(
        private JwtAuthService $jwt,
        private DeliveryQuoteService $quotes
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $auth = $this->jwt->decodeFromRequest($request);
        if (!$auth || ($auth['typ'] ?? null) !== 'mobile' || !isset($auth['uid'])) {
            return new JsonResponse(['message' => 'Unauthorized'], 401);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['message' => 'Invalid JSON body'], 400);
        }

        try {
            $departure = $this->readAddressReference($payload['departure'] ?? null, 'departure');
            $destination = $this->readDestination($payload['destination'] ?? null);

            return new JsonResponse($this->quotes->quote($departure, $destination));
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['message' => $e->getMessage()], 400);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['message' => $e->getMessage()], 404);
        } catch (\Throwable) {
            return new JsonResponse(['message' => 'Erreur lors du calcul de livraison'], 500);
        }
    }

    /**
     * @return array{addressName: string, userIdentifier: string}
     */
    private function readAddressReference(mixed $value, string $field): array
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException(sprintf('%s est requis', $field));
        }

        $addressName = $value['addressName'] ?? null;
        $userIdentifier = $value['userIdentifier'] ?? null;
        if (!is_string($addressName) || trim($addressName) === '') {
            throw new \InvalidArgumentException(sprintf('%s.addressName est requis', $field));
        }

        if (!is_string($userIdentifier) || trim($userIdentifier) === '') {
            throw new \InvalidArgumentException(sprintf('%s.userIdentifier est requis', $field));
        }

        return [
            'addressName' => trim($addressName),
            'userIdentifier' => trim($userIdentifier),
        ];
    }

    /**
     * @return string|array{addressName: string, userIdentifier: string}
     */
    private function readDestination(mixed $value): string|array
    {
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        return $this->readAddressReference($value, 'destination');
    }
}
