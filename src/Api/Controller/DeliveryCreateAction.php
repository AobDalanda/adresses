<?php

namespace App\Api\Controller;

use App\Exception\SubscriptionLimitReachedException;
use App\Service\DeliveryCreateService;
use App\Service\JwtAuthService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class DeliveryCreateAction
{
    public function __construct(
        private JwtAuthService $jwt,
        private DeliveryCreateService $deliveries
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
            $pickupReference = $this->readAddressReference($payload, 'pickup');
            $dropoffReference = $this->readAddressReference($payload, 'dropoff');
            $serviceType = $this->readRequiredString($payload['serviceType'] ?? null, 'serviceType');
            $vehicleType = $this->readRequiredString($payload['vehicleType'] ?? null, 'vehicleType');
            $notes = $this->readOptionalString($payload['notes'] ?? null, 'notes');
            $scheduledAt = $this->readOptionalDateTime($payload['scheduledAt'] ?? null, 'scheduledAt');
            $recipient = $this->readRecipient($payload['recipient'] ?? null);
            $package = $this->readPackage($payload['package'] ?? null);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['message' => $e->getMessage()], 400);
        }

        try {
            $result = $this->deliveries->create((int) $auth['uid'], [
                'pickup' => $pickupReference,
                'dropoff' => $dropoffReference,
                'serviceType' => $serviceType,
                'vehicleType' => $vehicleType,
                'scheduledAt' => $scheduledAt,
                'notes' => $notes,
                'recipient' => $recipient,
                'package' => $package,
            ]);
        } catch (SubscriptionLimitReachedException $e) {
            return new JsonResponse([
                'success' => false,
                'error' => [
                    'code' => $e->getErrorCode(),
                    'message' => $e->getMessage(),
                    'requiredPlan' => $e->getRequiredPlan(),
                ],
            ], 402);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['message' => $e->getMessage()], 400);
        } catch (\DomainException $e) {
            return new JsonResponse(['message' => $e->getMessage()], 409);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['message' => $e->getMessage()], 404);
        } catch (\Throwable) {
            return new JsonResponse(['message' => 'Erreur lors de la création de la livraison'], 500);
        }

        return new JsonResponse($result, 201);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{type: 'address'|'user_address', id: int}
     */
    private function readAddressReference(array $payload, string $prefix): array
    {
        $addressId = $this->readOptionalPositiveInt($payload[$prefix . 'AddressId'] ?? null, $prefix . 'AddressId');
        $userAddressId = $this->readOptionalPositiveInt($payload[$prefix . 'UserAddressId'] ?? null, $prefix . 'UserAddressId');

        if ($addressId !== null && $userAddressId !== null) {
            throw new \InvalidArgumentException(sprintf(
                'Utilisez soit %sAddressId soit %sUserAddressId, pas les deux',
                $prefix,
                $prefix
            ));
        }

        if ($addressId !== null) {
            return ['type' => 'address', 'id' => $addressId];
        }

        if ($userAddressId !== null) {
            return ['type' => 'user_address', 'id' => $userAddressId];
        }

        throw new \InvalidArgumentException(sprintf(
            '%sAddressId ou %sUserAddressId est requis',
            $prefix,
            $prefix
        ));
    }

    private function readRequiredPositiveInt(mixed $value, string $field): int
    {
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            throw new \InvalidArgumentException(sprintf('%s est requis', $field));
        }

        $normalized = (int) $value;
        if ($normalized <= 0) {
            throw new \InvalidArgumentException(sprintf('%s est invalide', $field));
        }

        return $normalized;
    }

    private function readRequiredString(mixed $value, string $field): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException(sprintf('%s est requis', $field));
        }

        return trim($value);
    }

    private function readOptionalString(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw new \InvalidArgumentException(sprintf('%s est invalide', $field));
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function readOptionalDateTime(mixed $value, string $field): ?\DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException(sprintf('%s est invalide', $field));
        }

        try {
            return new \DateTimeImmutable(trim($value));
        } catch (\Throwable) {
            throw new \InvalidArgumentException(sprintf('%s doit être une date ISO-8601 valide', $field));
        }
    }

    /**
     * @return array{name: ?string, phone: ?string}|null
     */
    private function readRecipient(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if (!is_array($value)) {
            throw new \InvalidArgumentException('recipient est invalide');
        }

        return [
            'name' => $this->readOptionalString($value['name'] ?? null, 'recipient.name'),
            'phone' => $this->readOptionalString($value['phone'] ?? null, 'recipient.phone'),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readPackage(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if (!is_array($value)) {
            throw new \InvalidArgumentException('package est invalide');
        }

        return [
            'description' => $this->readOptionalString($value['description'] ?? null, 'package.description'),
            'declaredValueAmount' => $this->readOptionalNumeric($value['declaredValueAmount'] ?? null, 'package.declaredValueAmount'),
            'declaredValueCurrency' => $this->readOptionalString($value['declaredValueCurrency'] ?? null, 'package.declaredValueCurrency'),
            'weightKg' => $this->readOptionalNumeric($value['weightKg'] ?? null, 'package.weightKg'),
            'lengthCm' => $this->readOptionalNumeric($value['lengthCm'] ?? null, 'package.lengthCm'),
            'widthCm' => $this->readOptionalNumeric($value['widthCm'] ?? null, 'package.widthCm'),
            'heightCm' => $this->readOptionalNumeric($value['heightCm'] ?? null, 'package.heightCm'),
            'fragile' => $this->readOptionalBool($value['fragile'] ?? null, 'package.fragile') ?? false,
            'signatureRequired' => $this->readOptionalBool($value['signatureRequired'] ?? null, 'package.signatureRequired') ?? false,
            'photoAssetId' => $this->readOptionalPositiveInt($value['photoAssetId'] ?? null, 'package.photoAssetId'),
        ];
    }

    private function readOptionalNumeric(mixed $value, string $field): ?float
    {
        if ($value === null) {
            return null;
        }

        if (!is_numeric($value)) {
            throw new \InvalidArgumentException(sprintf('%s est invalide', $field));
        }

        return (float) $value;
    }

    private function readOptionalBool(mixed $value, string $field): ?bool
    {
        if ($value === null) {
            return null;
        }

        if (!is_bool($value)) {
            throw new \InvalidArgumentException(sprintf('%s est invalide', $field));
        }

        return $value;
    }

    private function readOptionalPositiveInt(mixed $value, string $field): ?int
    {
        if ($value === null) {
            return null;
        }

        return $this->readRequiredPositiveInt($value, $field);
    }
}
