<?php

namespace App\Api\Controller;

use App\Dto\DriverRegistrationInput;
use App\Service\DriverRegistrationService;
use App\Service\JwtAuthService;
use App\Service\OtpService;
use App\Service\Subscription\SubscriptionManager;
use App\Service\UserAccountAssetUrlResolver;
use App\Service\UserAccountService;
use App\Util\PhoneNumberNormalizer;
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
        private readonly UserAccountAssetUrlResolver $assetUrlResolver
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
            $user = $this->users->upsertUserAccount(
                $input->phone,
                $input->fullName,
                true,
                null,
                $this->mapAccountType($input->signupAs),
                $input->identityDocumentPath,
                $input->driverLicense['photoPath'],
                $input->email,
                $input->identityDocumentNumber
            );

            $this->subscriptions->initializeFreeSubscription((int) $user['id']);
            $application = $this->driverRegistrations->register((int) $user['id'], $input, $request->getClientIp());
            $tokenVersion = $this->users->rotateTokenVersion((int) $user['id']);
            $token = $this->jwt->issueToken([
                'sub' => $input->phone,
                'typ' => 'mobile',
                'uid' => $user['id'],
                'tv' => $tokenVersion,
            ]);
        } catch (\Throwable) {
            return new JsonResponse(['message' => 'Erreur lors de la soumission de l’inscription livreur'], 500);
        }

        return new JsonResponse([
            'token' => $token,
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

        $signupAs = $this->requireString($profile, 'signupAs');
        if (!in_array($signupAs, self::ALLOWED_SIGNUP_AS, true)) {
            throw new \InvalidArgumentException('signupAs est invalide');
        }

        $fullName = $this->requireString($profile, 'fullName');
        $email = $this->requireString($profile, 'email');
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('email est invalide');
        }

        $identityDocumentNumber = $this->requireString($profile, 'identityDocumentNumber');
        $identityDocumentPath = $this->requireString($profile, 'identityDocumentPath');

        $vehicleType = $this->requireString($vehicle, 'type');
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

            $normalizedZones[] = trim($zone);
        }

        $requiresMotorizedDocuments = $vehicleType !== 'A_PIED';
        $brand = $this->optionalString($vehicle, 'brand');
        $model = $this->optionalString($vehicle, 'model');
        $licensePlate = $this->optionalString($vehicle, 'licensePlate');

        if ($requiresMotorizedDocuments && ($brand === null || $model === null || $licensePlate === null)) {
            throw new \InvalidArgumentException('vehicle.brand, vehicle.model et vehicle.licensePlate sont requis');
        }

        $driverLicenseNumber = $this->optionalString($driverLicense, 'number');
        $driverLicenseCategory = $this->optionalString($driverLicense, 'category');
        $driverLicenseExpiryDate = $this->optionalString($driverLicense, 'expiryDate');
        $driverLicensePhotoPath = $this->optionalString($driverLicense, 'photoPath');

        if ($requiresMotorizedDocuments) {
            if ($driverLicenseNumber === null || $driverLicenseCategory === null || $driverLicenseExpiryDate === null || $driverLicensePhotoPath === null) {
                throw new \InvalidArgumentException('driverLicense.number, category, expiryDate et photoPath sont requis');
            }

            $driverLicenseExpiryDate = $this->normalizeDate($driverLicenseExpiryDate);
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
        }

        $normalizedVehiclePhotoPaths = [];
        if (is_array($vehiclePhotoPaths)) {
            foreach ($vehiclePhotoPaths as $filePath) {
                if (!is_string($filePath) || trim($filePath) === '') {
                    throw new \InvalidArgumentException('vehiclePhotoPaths contient une valeur invalide');
                }

                $normalizedVehiclePhotoPaths[] = trim($filePath);
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

    private function normalizeDate(string $value): string
    {
        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value)
            ?: \DateTimeImmutable::createFromFormat('d/m/Y', $value);

        if (!$date instanceof \DateTimeImmutable) {
            throw new \InvalidArgumentException('driverLicense.expiryDate est invalide');
        }

        return $date->format('Y-m-d');
    }

    private function mapAccountType(string $signupAs): string
    {
        return match ($signupAs) {
            'LIVREUR' => 'driver',
            'TRANSPORTEUR' => 'transporter',
            'BOTH' => 'driver_transport',
            default => 'client',
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
