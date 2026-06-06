<?php

namespace App\Dto;

final class DriverRegistrationInput
{
    /**
     * @param array{
     *     type: string,
     *     brand: ?string,
     *     model: ?string,
     *     licensePlate: ?string,
     *     deliveryZones: list<string>
     * } $vehicle
     * @param array{
     *     number: ?string,
     *     category: ?string,
     *     expiryDate: ?string,
     *     photoPath: ?string
     * } $driverLicense
     * @param array{
     *     insurancePath: ?string,
     *     registrationPath: ?string,
     *     registrationFrontPath: ?string,
     *     registrationBackPath: ?string
     * } $vehicleDocuments
     * @param list<string> $vehiclePhotoPaths
     */
    public function __construct(
        public string $phone,
        public string $otp,
        public string $signupAs,
        public string $fullName,
        public ?string $email,
        public string $identityDocumentNumber,
        public string $identityDocumentPath,
        public array $vehicle,
        public array $driverLicense,
        public array $vehicleDocuments,
        public array $vehiclePhotoPaths
    ) {
    }
}
