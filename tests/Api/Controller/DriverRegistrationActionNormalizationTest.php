<?php

declare(strict_types=1);

namespace App\Tests\Api\Controller;

use App\Api\Controller\DriverRegistrationAction;
use App\Dto\DriverRegistrationInput;
use PHPUnit\Framework\TestCase;

final class DriverRegistrationActionNormalizationTest extends TestCase
{
    public function testSelectorPresentationSuffixIsRemoved(): void
    {
        $controller = (new \ReflectionClass(DriverRegistrationAction::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(DriverRegistrationAction::class, 'optionalSelectorString');

        self::assertSame(
            'A',
            $method->invoke($controller, ['category' => 'A                              ⌄'], 'category')
        );
        self::assertSame(
            'DK 150',
            $method->invoke($controller, ['model' => 'DK 150                         ⌄'], 'model')
        );
    }

    public function testWalkingRegistrationIgnoresVehicleAndLicenseDocuments(): void
    {
        $controller = (new \ReflectionClass(DriverRegistrationAction::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(DriverRegistrationAction::class, 'buildInput');

        $input = $method->invoke($controller, [
            'phone' => '620000000',
            'otp' => '123456',
            'profile' => [
                'signupAs' => 'livreur',
                'fullName' => 'Mamadou Diallo',
                'email' => 'MAMADOU@example.com',
                'identityDocumentNumber' => 'CNI-123',
                'identityDocumentPath' => 'supabase://identity-documents/cni.jpg',
            ],
            'vehicle' => [
                'type' => 'a_pied',
                'brand' => 'ignored',
                'model' => 'ignored',
                'licensePlate' => 'ignored',
                'deliveryZones' => ['Conakry'],
            ],
            'driverLicense' => [
                'number' => 'ignored',
                'category' => 'A',
                'expiryDate' => '2030-01-01',
                'photoPath' => 'ignored',
            ],
            'vehicleDocuments' => [
                'insurancePath' => 'ignored',
                'registrationPath' => 'ignored',
                'registrationFrontPath' => 'ignored',
                'registrationBackPath' => 'ignored',
            ],
            'vehiclePhotoPaths' => ['ignored'],
        ]);

        self::assertInstanceOf(DriverRegistrationInput::class, $input);
        self::assertSame('LIVREUR', $input->signupAs);
        self::assertSame('mamadou@example.com', $input->email);
        self::assertSame('A_PIED', $input->vehicle['type']);
        self::assertNull($input->vehicle['brand']);
        self::assertNull($input->vehicle['licensePlate']);
        self::assertNull($input->driverLicense['number']);
        self::assertNull($input->vehicleDocuments['insurancePath']);
        self::assertSame([], $input->vehiclePhotoPaths);
    }

    public function testRegistrationRejectsDatabaseOverflowBeforeInsert(): void
    {
        $controller = (new \ReflectionClass(DriverRegistrationAction::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(DriverRegistrationAction::class, 'buildInput');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('profile.fullName ne doit pas dépasser 100 caractères');

        $method->invoke($controller, [
            'phone' => '620000000',
            'otp' => '123456',
            'profile' => [
                'signupAs' => 'LIVREUR',
                'fullName' => str_repeat('A', 101),
                'email' => 'mamadou@example.com',
                'identityDocumentNumber' => 'CNI-123',
                'identityDocumentPath' => 'supabase://identity-documents/cni.jpg',
            ],
            'vehicle' => [
                'type' => 'A_PIED',
                'deliveryZones' => ['Conakry'],
            ],
            'driverLicense' => [],
            'vehicleDocuments' => [],
            'vehiclePhotoPaths' => [],
        ]);
    }
}
