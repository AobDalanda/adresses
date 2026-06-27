<?php

namespace App\Api\Controller;

use App\Dto\DriverRegistrationInput;
use App\Service\DriverRegistrationService;
use App\Service\JwtAuthService;
use App\Service\OtpService;
use App\Service\ProviderProfileService;
use App\Service\Subscription\SubscriptionManager;
use App\Service\UserAccountAssetUrlResolver;
use App\Service\UserAccountService;
use App\Util\PhoneNumberNormalizer;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class DriverRegistrationAction
{
    private const ALLOWED_SIGNUP_AS = ['LIVREUR', 'TRANSPORTEUR', 'BOTH'];
    private const ALLOWED_VEHICLE_TYPES = ['MOTO', 'VOITURE', 'VELO', 'A_PIED'];

    public function __construct(
        private readonly OtpService $otpService,
        private readonly UserAccountService $users,
        private readonly DriverRegistrationService $driverRegistrations,
        private readonly SubscriptionManager $subscriptions,
        private readonly JwtAuthService $jwt,
        private readonly UserAccountAssetUrlResolver $assetUrlResolver,
        private readonly ProviderProfileService $providerProfiles,
        private readonly LoggerInterface $logger,
        private readonly Connection $db
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['message' => 'Invalid JSON body'], 400);
        }

        try {
            $input = $this->buildInput($payload);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['message' => $e->getMessage()], 400);
        }

        if (!$this->otpService->verifyOtp($input->phone, $input->otp)) {
            return new JsonResponse(['message' => 'OTP invalide'], 401);
        }

        try {
            [$user, $application, $tokenVersion] = $this->db->transactional(
                function () use ($input, $request): array {
                    $user = $this->users->upsertUserAccount(
                        $input->phone,
                        $input->fullName,
                        true,
                        null,
                        'client',
                        $input->identityDocumentPath,
                        $input->driverLicense['photoPath'],
                        $input->email,
                        $input->identityDocumentNumber
                    );

                    $this->providerProfiles->submitActivities(
                        (int) $user['id'],
                        in_array($input->signupAs, ['LIVREUR', 'BOTH'], true),
                        in_array($input->signupAs, ['TRANSPORTEUR', 'BOTH'], true)
                    );
                    $this->subscriptions->initializeFreeSubscription((int) $user['id']);
                    $application = $this->driverRegistrations->register(
                        (int) $user['id'],
                        $input,
                        $request->getClientIp()
                    );
                    $tokenVersion = $this->users->rotateTokenVersion((int) $user['id']);

                    return [$user, $application, $tokenVersion];
                }
            );
            $token = $this->jwt->issueToken([
                'sub' => $input->phone,
                'typ' => 'mobile',
                'uid' => $user['id'],
                'tv' => $tokenVersion,
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            $this->logger->warning('Driver registration uniqueness conflict', [
                'phone' => $input->phone,
                'signupAs' => $input->signupAs,
                'exception' => $exception,
            ]);

            return new JsonResponse([
                'message' => $this->uniqueConflictMessage($exception),
            ], 409);
        } catch (\Throwable $exception) {
            $this->logger->error('Driver registration failed', [
                'phone' => $input->phone,
                'signupAs' => $input->signupAs,
                'exception' => $exception,
            ]);

            return new JsonResponse(['message' => 'Erreur lors de la soumission de l’inscription livreur'], 500);
        }

        return new JsonResponse([
            'token' => $token,
            'refreshToken' => $this->jwt->issueToken([
                'sub' => $input->phone,
                'typ' => 'mobile_refresh',
                'uid' => $user['id'],
                'tv' => $tokenVersion,
            ], JwtAuthService::REFRESH_TOKEN_TTL_SECONDS),
            'user' => $this->userPayload($user),
            'application' => $application,
        ], 201);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function buildInput(array $payload): DriverRegistrationInput
    {
        $phone = $payload['phone'] ?? null;
        $otp = $payload['otp'] ?? null;
        $profile = $payload['profile'] ?? null;
        $vehicle = $payload['vehicle'] ?? null;
        $driverLicense = $payload['driverLicense'] ?? null;
        $vehicleDocuments = $payload['vehicleDocuments'] ?? null;
        $vehiclePhotoPaths = $payload['vehiclePhotoPaths'] ?? null;

        if (!is_string($phone) || trim($phone) === '' || !is_string($otp) || trim($otp) === '') {
            throw new \InvalidArgumentException('phone et otp sont requis');
        }

        if (!is_array($profile) || !is_array($vehicle) || !is_array($driverLicense) || !is_array($vehicleDocuments)) {
            throw new \InvalidArgumentException('profile, vehicle, driverLicense et vehicleDocuments sont requis');
        }

        $normalizedPhone = PhoneNumberNormalizer::normalize($phone);
        if ($normalizedPhone === '') {
            throw new \InvalidArgumentException('phone est invalide');
        }
        $this->assertMaxLength($normalizedPhone, 20, 'phone');

        $signupAs = strtoupper($this->requireString($profile, 'signupAs'));
        if (!in_array($signupAs, self::ALLOWED_SIGNUP_AS, true)) {
            throw new \InvalidArgumentException('signupAs est invalide');
        }

        $fullName = $this->requireString($profile, 'fullName');
        $this->assertMaxLength($fullName, 100, 'profile.fullName');
        $email = $this->requireString($profile, 'email');
        $email = strtolower($email);
        $this->assertMaxLength($email, 180, 'profile.email');
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('email est invalide');
        }

        $identityDocumentNumber = $this->requireString($profile, 'identityDocumentNumber');
        $identityDocumentPath = $this->requireString($profile, 'identityDocumentPath');
        $this->assertMaxLength($identityDocumentNumber, 100, 'profile.identityDocumentNumber');
        $this->assertMaxLength($identityDocumentPath, 255, 'profile.identityDocumentPath');

        $vehicleType = strtoupper($this->requireString($vehicle, 'type'));
        if (!in_array($vehicleType, self::ALLOWED_VEHICLE_TYPES, true)) {
            throw new \InvalidArgumentException('vehicle.type est invalide');
        }

        $deliveryZones = $vehicle['deliveryZones'] ?? null;
        if (!is_array($deliveryZones) || $deliveryZones === []) {
            throw new \InvalidArgumentException('vehicle.deliveryZones est requis');
        }

        $normalizedZones = [];
        foreach ($deliveryZones as $zone) {
            if (!is_string($zone) || trim($zone) === '') {
                throw new \InvalidArgumentException('vehicle.deliveryZones contient une valeur invalide');
            }

            $normalizedZone = trim($zone);
            $this->assertMaxLength($normalizedZone, 100, 'vehicle.deliveryZones');
            $normalizedZones[] = $normalizedZone;
        }

        $requiresMotorizedDocuments = $vehicleType !== 'A_PIED';
        $brand = $this->optionalSelectorString($vehicle, 'brand');
        $model = $this->optionalSelectorString($vehicle, 'model');
        $licensePlate = $this->optionalString($vehicle, 'licensePlate');
        if ($brand !== null) {
            $this->assertMaxLength($brand, 100, 'vehicle.brand');
        }
        if ($model !== null) {
            $this->assertMaxLength($model, 100, 'vehicle.model');
        }
        if ($licensePlate !== null) {
            $licensePlate = strtoupper($licensePlate);
            $this->assertMaxLength($licensePlate, 50, 'vehicle.licensePlate');
        }

        if ($requiresMotorizedDocuments && ($brand === null || $model === null || $licensePlate === null)) {
            throw new \InvalidArgumentException('vehicle.brand, vehicle.model et vehicle.licensePlate sont requis');
        }

        $driverLicenseNumber = $this->optionalString($driverLicense, 'number');
        $driverLicenseCategory = $this->optionalSelectorString($driverLicense, 'category');
        $driverLicenseExpiryDate = $this->optionalString($driverLicense, 'expiryDate');
        $driverLicensePhotoPath = $this->optionalString($driverLicense, 'photoPath');

        if ($requiresMotorizedDocuments) {
            if ($driverLicenseNumber === null || $driverLicenseCategory === null || $driverLicenseExpiryDate === null || $driverLicensePhotoPath === null) {
                throw new \InvalidArgumentException('driverLicense.number, category, expiryDate et photoPath sont requis');
            }

            $driverLicenseExpiryDate = $this->normalizeDate($driverLicenseExpiryDate);
            $this->assertMaxLength($driverLicenseNumber, 100, 'driverLicense.number');
            $this->assertMaxLength($driverLicenseCategory, 20, 'driverLicense.category');
            $this->assertMaxLength($driverLicensePhotoPath, 255, 'driverLicense.photoPath');
        } else {
            $brand = null;
            $model = null;
            $licensePlate = null;
            $driverLicenseNumber = null;
            $driverLicenseCategory = null;
            $driverLicenseExpiryDate = null;
            $driverLicensePhotoPath = null;
        }

        $insurancePath = $this->optionalString($vehicleDocuments, 'insurancePath');
        $registrationPath = $this->optionalString($vehicleDocuments, 'registrationPath');
        $registrationFrontPath = $this->optionalString($vehicleDocuments, 'registrationFrontPath');
        $registrationBackPath = $this->optionalString($vehicleDocuments, 'registrationBackPath');

        if ($requiresMotorizedDocuments) {
            if ($insurancePath === null || $registrationPath === null || $registrationFrontPath === null || $registrationBackPath === null) {
                throw new \InvalidArgumentException('vehicleDocuments.* est requis pour ce type de véhicule');
            }

            if (!is_array($vehiclePhotoPaths) || $vehiclePhotoPaths === []) {
                throw new \InvalidArgumentException('vehiclePhotoPaths est requis pour ce type de véhicule');
            }
        } else {
            $insurancePath = null;
            $registrationPath = null;
            $registrationFrontPath = null;
            $registrationBackPath = null;
            $vehiclePhotoPaths = [];
        }

        foreach ([
            'vehicleDocuments.insurancePath' => $insurancePath,
            'vehicleDocuments.registrationPath' => $registrationPath,
            'vehicleDocuments.registrationFrontPath' => $registrationFrontPath,
            'vehicleDocuments.registrationBackPath' => $registrationBackPath,
        ] as $field => $path) {
            if ($path !== null) {
                $this->assertMaxLength($path, 255, $field);
            }
        }

        $normalizedVehiclePhotoPaths = [];
        if (is_array($vehiclePhotoPaths)) {
            foreach ($vehiclePhotoPaths as $filePath) {
                if (!is_string($filePath) || trim($filePath) === '') {
                    throw new \InvalidArgumentException('vehiclePhotoPaths contient une valeur invalide');
                }

                $normalizedPath = trim($filePath);
                $this->assertMaxLength($normalizedPath, 255, 'vehiclePhotoPaths');
                $normalizedVehiclePhotoPaths[] = $normalizedPath;
            }
        }

        return new DriverRegistrationInput(
            $normalizedPhone,
            trim($otp),
            $signupAs,
            $fullName,
            $email,
            $identityDocumentNumber,
            $identityDocumentPath,
            [
                'type' => $vehicleType,
                'brand' => $brand,
                'model' => $model,
                'licensePlate' => $licensePlate,
                'deliveryZones' => $normalizedZones,
            ],
            [
                'number' => $driverLicenseNumber,
                'category' => $driverLicenseCategory,
                'expiryDate' => $driverLicenseExpiryDate,
                'photoPath' => $driverLicensePhotoPath,
            ],
            [
                'insurancePath' => $insurancePath,
                'registrationPath' => $registrationPath,
                'registrationFrontPath' => $registrationFrontPath,
                'registrationBackPath' => $registrationBackPath,
            ],
            $normalizedVehiclePhotoPaths
        );
    }

    /**
     * @param array<string, mixed> $values
     */
    private function requireString(array $values, string $key): string
    {
        if (!isset($values[$key]) || !is_string($values[$key]) || trim($values[$key]) === '') {
            throw new \InvalidArgumentException(sprintf('%s est requis', $key));
        }

        return trim($values[$key]);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function optionalString(array $values, string $key): ?string
    {
        if (!array_key_exists($key, $values) || $values[$key] === null) {
            return null;
        }

        if (!is_string($values[$key]) || trim($values[$key]) === '') {
            throw new \InvalidArgumentException(sprintf('%s est invalide', $key));
        }

        return trim($values[$key]);
    }

    /**
     * Android selector values may include presentation spacing and a trailing dropdown glyph.
     *
     * @param array<string, mixed> $values
     */
    private function optionalSelectorString(array $values, string $key): ?string
    {
        $value = $this->optionalString($values, $key);
        if ($value === null) {
            return null;
        }

        $normalized = preg_replace('/\s*[⌄▼▾]\s*$/u', '', $value) ?? $value;
        $normalized = trim(preg_replace('/\s+/u', ' ', $normalized) ?? $normalized);

        return $normalized === '' ? null : $normalized;
    }

    private function normalizeDate(string $value): string
    {
        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value)
            ?: \DateTimeImmutable::createFromFormat('d/m/Y', $value);

        if (!$date instanceof \DateTimeImmutable) {
            throw new \InvalidArgumentException('driverLicense.expiryDate est invalide');
        }

        return $date->format('Y-m-d');
    }

    private function assertMaxLength(string $value, int $maxLength, string $field): void
    {
        if (mb_strlen($value) > $maxLength) {
            throw new \InvalidArgumentException(sprintf(
                '%s ne doit pas dépasser %d caractères',
                $field,
                $maxLength
            ));
        }
    }

    private function uniqueConflictMessage(UniqueConstraintViolationException $exception): string
    {
        $message = $exception->getMessage();

        return match (true) {
            str_contains($message, 'uniq_user_account_identity_document_number')
                => 'Ce numéro de pièce d’identité est déjà enregistré',
            str_contains($message, 'uniq_driver_license_number')
                => 'Ce numéro de permis est déjà enregistré',
            str_contains($message, 'uniq_driver_vehicle_license_plate')
                => 'Cette plaque d’immatriculation est déjà enregistrée',
            str_contains($message, 'user_account_phone_key')
                => 'Ce numéro de téléphone est déjà enregistré',
            str_contains($message, 'uniq_user_account_email')
                => 'Cette adresse email est déjà enregistrée',
            default => 'Une donnée unique est déjà enregistrée',
        };
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
