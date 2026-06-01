<?php

namespace App\Api\Controller;

use App\Service\AccountDocumentStorage;
use App\Service\OtpService;
use App\Service\ProfilePhotoStorage;
use App\Service\UserAccountService;
use App\Util\PhoneNumberNormalizer;
use JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

final class UserAccountRegisterAction
{
    public function __construct(
        private UserAccountService $userAccountService,
        private OtpService $otpService,
        private ProfilePhotoStorage $profilePhotoStorage,
        private AccountDocumentStorage $accountDocumentStorage
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        try {
            $payload = $this->extractPayload($request);
        } catch (JsonException) {
            return new JsonResponse(['message' => 'Invalid JSON body'], 400);
        }

        $phone = $payload['phone'] ?? null;
        $fullName = $payload['fullName'] ?? $payload['name'] ?? null;
        $email = $payload['email'] ?? null;
        $identityDocumentNumber = $payload['identityDocumentNumber']
            ?? $payload['identityNumber']
            ?? $payload['documentNumber']
            ?? null;

        if (!is_string($phone) || trim($phone) === '') {
            return new JsonResponse(['message' => 'phone est requis'], 400);
        }

        if (!is_string($fullName) || trim($fullName) === '') {
            return new JsonResponse(['message' => 'fullName est requis'], 400);
        }

        $phone = PhoneNumberNormalizer::normalize($phone);
        $fullName = trim(preg_replace('/\s+/', ' ', $fullName) ?? $fullName);

        if ($phone === '') {
            return new JsonResponse(['message' => 'phone est invalide'], 400);
        }

        if (strlen($phone) > 20) {
            return new JsonResponse(['message' => 'phone ne doit pas dépasser 20 caractères'], 400);
        }

        if (strlen($fullName) > 100) {
            return new JsonResponse(['message' => 'fullName ne doit pas dépasser 100 caractères'], 400);
        }

        if ($email !== null && !is_string($email)) {
            return new JsonResponse(['message' => 'email est invalide'], 400);
        }

        $email = is_string($email) ? strtolower(trim($email)) : null;
        if ($email !== null && $email !== '') {
            if (mb_strlen($email) > 180) {
                return new JsonResponse(['message' => 'email ne doit pas dépasser 180 caractères'], 400);
            }

            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                return new JsonResponse(['message' => 'email est invalide'], 400);
            }
        } else {
            $email = null;
        }

        if ($identityDocumentNumber !== null && !is_string($identityDocumentNumber)) {
            return new JsonResponse(['message' => 'identityDocumentNumber est invalide'], 400);
        }

        $identityDocumentNumber = is_string($identityDocumentNumber)
            ? trim(preg_replace('/\s+/', ' ', $identityDocumentNumber) ?? $identityDocumentNumber)
            : null;
        if ($identityDocumentNumber === '') {
            $identityDocumentNumber = null;
        }

        if ($identityDocumentNumber !== null && mb_strlen($identityDocumentNumber) > 100) {
            return new JsonResponse(['message' => 'identityDocumentNumber ne doit pas dépasser 100 caractères'], 400);
        }

        $accountType = $this->normalizeAccountType($payload['accountType'] ?? 'client');
        if ($accountType === null) {
            return new JsonResponse(['message' => 'accountType est invalide'], 400);
        }

        if ($this->userAccountService->userExists($phone)) {
            return new JsonResponse(['message' => 'Un compte existe déjà pour ce numéro de téléphone'], 409);
        }

        $existingRegistration = $this->userAccountService->findPendingRegistration($phone);
        $profilePhotoPath = null;
        $identityDocumentPath = null;
        $driverLicensePath = null;
        $profilePhoto = $request->files->get('profilePhoto');
        $identityDocument = $request->files->get('identityDocument');
        $driverLicense = $request->files->get('driverLicense');

        if ($accountType === 'livreur') {
            if (!$profilePhoto instanceof UploadedFile) {
                return new JsonResponse(['message' => 'profilePhoto est requis pour un compte livreur'], 400);
            }

            if (!$identityDocument instanceof UploadedFile) {
                return new JsonResponse(['message' => 'identityDocument est requis pour un compte livreur'], 400);
            }

            if (!$driverLicense instanceof UploadedFile) {
                return new JsonResponse(['message' => 'driverLicense est requis pour un compte livreur'], 400);
            }
        }

        if ($profilePhoto !== null) {
            if (!$profilePhoto instanceof UploadedFile) {
                return new JsonResponse(['message' => 'profilePhoto est invalide'], 400);
            }

            try {
                $profilePhotoPath = $this->profilePhotoStorage->store($profilePhoto);
            } catch (\RuntimeException $e) {
                return new JsonResponse(['message' => $e->getMessage()], 400);
            } catch (\Throwable) {
                return new JsonResponse(['message' => 'Erreur lors de l’enregistrement de la photo de profil'], 500);
            }
        }

        if ($identityDocument !== null) {
            if (!$identityDocument instanceof UploadedFile) {
                $this->profilePhotoStorage->deleteIfStored($profilePhotoPath);

                return new JsonResponse(['message' => 'identityDocument est invalide'], 400);
            }

            try {
                $identityDocumentPath = $this->accountDocumentStorage->storeIdentityDocument($identityDocument);
            } catch (\RuntimeException $e) {
                $this->profilePhotoStorage->deleteIfStored($profilePhotoPath);

                return new JsonResponse(['message' => $e->getMessage()], 400);
            } catch (\Throwable) {
                $this->profilePhotoStorage->deleteIfStored($profilePhotoPath);

                return new JsonResponse(['message' => 'Erreur lors de l’enregistrement de la pièce d’identité'], 500);
            }
        }

        if ($driverLicense !== null) {
            if (!$driverLicense instanceof UploadedFile) {
                $this->profilePhotoStorage->deleteIfStored($profilePhotoPath);
                $this->accountDocumentStorage->deleteIfStored($identityDocumentPath);

                return new JsonResponse(['message' => 'driverLicense est invalide'], 400);
            }

            try {
                $driverLicensePath = $this->accountDocumentStorage->storeDriverLicense($driverLicense);
            } catch (\RuntimeException $e) {
                $this->profilePhotoStorage->deleteIfStored($profilePhotoPath);
                $this->accountDocumentStorage->deleteIfStored($identityDocumentPath);

                return new JsonResponse(['message' => $e->getMessage()], 400);
            } catch (\Throwable) {
                $this->profilePhotoStorage->deleteIfStored($profilePhotoPath);
                $this->accountDocumentStorage->deleteIfStored($identityDocumentPath);

                return new JsonResponse(['message' => 'Erreur lors de l’enregistrement du permis de conduire'], 500);
            }
        }

        $this->userAccountService->createPendingRegistration(
            $phone,
            $fullName,
            $profilePhotoPath,
            $accountType,
            $identityDocumentPath,
            $driverLicensePath,
            $email,
            $identityDocumentNumber
        );
        if (
            $profilePhotoPath !== null
            && is_array($existingRegistration)
            && ($existingRegistration['profilePhotoPath'] ?? null) !== $profilePhotoPath
        ) {
            $this->profilePhotoStorage->deleteIfStored($existingRegistration['profilePhotoPath'] ?? null);
        }
        if (
            $identityDocumentPath !== null
            && is_array($existingRegistration)
            && ($existingRegistration['identityDocumentPath'] ?? null) !== $identityDocumentPath
        ) {
            $this->accountDocumentStorage->deleteIfStored($existingRegistration['identityDocumentPath'] ?? null);
        }
        if (
            $driverLicensePath !== null
            && is_array($existingRegistration)
            && ($existingRegistration['driverLicensePath'] ?? null) !== $driverLicensePath
        ) {
            $this->accountDocumentStorage->deleteIfStored($existingRegistration['driverLicensePath'] ?? null);
        }

        try {
            $this->otpService->requestOtp($phone);
        } catch (\Throwable) {
            return new JsonResponse(['message' => 'Erreur lors de l’envoi OTP'], 500);
        }

        return new JsonResponse(['message' => 'OTP envoyé'], 202);
    }

    /**
     * @return array<string, mixed>
     */
    private function extractPayload(Request $request): array
    {
        if (str_starts_with((string) $request->headers->get('Content-Type', ''), 'multipart/form-data')) {
            return $request->request->all();
        }

        $payload = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($payload)) {
            throw new JsonException('Invalid JSON body');
        }

        return $payload;
    }

    private function normalizeAccountType(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $normalized = strtolower(trim($value));

        return in_array($normalized, ['client', 'livreur'], true) ? $normalized : null;
    }
}
