<?php

namespace App\Service;

use App\Dto\DriverRegistrationInput;
use Doctrine\DBAL\Connection;

final class DriverRegistrationService
{
    public function __construct(
        private Connection $db,
        private readonly ProviderCanonicalRegistrationWriter $canonicalWriter,
        private readonly bool $canonicalWriteEnabled,
    ) {
    }

    /**
     * @return array{applicationId: int, status: string}
     */
    public function register(int $userId, DriverRegistrationInput $input, ?string $clientIp = null): array
    {
        return $this->db->transactional(function () use ($userId, $input, $clientIp): array {
            $applicationId = (int) $this->db->fetchOne(
                '
                INSERT INTO driver_application (
                    user_id,
                    phone,
                    signup_as,
                    status,
                    submitted_at
                )
                VALUES (
                    :userId,
                    :phone,
                    :signupAs,
                    :status,
                    now()
                )
                RETURNING id
                ',
                [
                    'userId' => $userId,
                    'phone' => $input->phone,
                    'signupAs' => $input->signupAs,
                    'status' => 'PENDING',
                ]
            );

            $this->db->executeStatement(
                '
                INSERT INTO driver_vehicle (
                    application_id,
                    vehicle_type,
                    brand,
                    model,
                    license_plate
                )
                VALUES (
                    :applicationId,
                    :vehicleType,
                    :brand,
                    :model,
                    :licensePlate
                )
                ',
                [
                    'applicationId' => $applicationId,
                    'vehicleType' => $input->vehicle['type'],
                    'brand' => $input->vehicle['brand'],
                    'model' => $input->vehicle['model'],
                    'licensePlate' => $input->vehicle['licensePlate'],
                ]
            );

            if (
                $input->driverLicense['number'] !== null
                || $input->driverLicense['category'] !== null
                || $input->driverLicense['expiryDate'] !== null
                || $input->driverLicense['photoPath'] !== null
            ) {
                $this->db->executeStatement(
                    '
                    INSERT INTO driver_license (
                        application_id,
                        license_number,
                        category,
                        expiry_date,
                        license_photo_path
                    )
                    VALUES (
                        :applicationId,
                        :licenseNumber,
                        :category,
                        :expiryDate,
                        :licensePhotoPath
                    )
                    ',
                    [
                        'applicationId' => $applicationId,
                        'licenseNumber' => $input->driverLicense['number'],
                        'category' => $input->driverLicense['category'],
                        'expiryDate' => $input->driverLicense['expiryDate'],
                        'licensePhotoPath' => $input->driverLicense['photoPath'],
                    ]
                );
            }

            $documents = [
                'INSURANCE' => $input->vehicleDocuments['insurancePath'],
                'REGISTRATION' => $input->vehicleDocuments['registrationPath'],
                'REGISTRATION_FRONT' => $input->vehicleDocuments['registrationFrontPath'],
                'REGISTRATION_BACK' => $input->vehicleDocuments['registrationBackPath'],
            ];

            foreach ($documents as $documentType => $filePath) {
                if ($filePath === null || $filePath === '') {
                    continue;
                }

                $this->db->executeStatement(
                    '
                    INSERT INTO driver_vehicle_document (application_id, document_type, file_path)
                    VALUES (:applicationId, :documentType, :filePath)
                    ',
                    [
                        'applicationId' => $applicationId,
                        'documentType' => $documentType,
                        'filePath' => $filePath,
                    ]
                );
            }

            foreach ($input->vehicle['deliveryZones'] as $zoneName) {
                $this->db->executeStatement(
                    '
                    INSERT INTO driver_delivery_zone (application_id, zone_name)
                    VALUES (:applicationId, :zoneName)
                    ',
                    [
                        'applicationId' => $applicationId,
                        'zoneName' => $zoneName,
                    ]
                );
            }

            foreach ($input->vehiclePhotoPaths as $index => $filePath) {
                $this->db->executeStatement(
                    '
                    INSERT INTO driver_vehicle_photo (application_id, file_path, sort_order)
                    VALUES (:applicationId, :filePath, :sortOrder)
                    ',
                    [
                        'applicationId' => $applicationId,
                        'filePath' => $filePath,
                        'sortOrder' => $index,
                    ]
                );
            }

            $this->db->executeStatement(
                "
                INSERT INTO audit_log (actor, action, target, ip_address)
                VALUES (:actor, 'SUBMIT_DRIVER_APPLICATION', :target, :ip)
                ",
                [
                    'actor' => $input->phone,
                    'target' => (string) $applicationId,
                    'ip' => $clientIp,
                ]
            );

            if ($this->canonicalWriteEnabled) {
                $this->canonicalWriter->write($userId, $applicationId, $input);
            }

            return [
                'applicationId' => $applicationId,
                'status' => 'PENDING',
            ];
        });
    }
}
