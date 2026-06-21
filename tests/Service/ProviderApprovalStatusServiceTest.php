<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ProviderApprovalStatusService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class ProviderApprovalStatusServiceTest extends TestCase
{
    public function testReturnsActivatedStepWhenAuthorizationIsActive(): void
    {
        $service = new ProviderApprovalStatusService($this->createMock(Connection::class));

        $step = $service->currentStep(
            ['validation_status' => 'approved'],
            ['status' => 'APPROVED'],
            ['status' => 'ACTIVE'],
            ['submitted' => true, 'requiredCount' => 2, 'submittedCount' => 2, 'missingTypes' => []],
        );

        self::assertSame(ProviderApprovalStatusService::STEP_ACCOUNT_ACTIVATED, $step);
    }

    public function testReturnsVerificationInProgressForReviewStatuses(): void
    {
        $service = new ProviderApprovalStatusService($this->createMock(Connection::class));

        $step = $service->currentStep(
            ['validation_status' => 'pending'],
            ['status' => 'UNDER_REVIEW'],
            ['status' => 'INACTIVE'],
            ['submitted' => true, 'requiredCount' => 2, 'submittedCount' => 2, 'missingTypes' => []],
        );

        self::assertSame(ProviderApprovalStatusService::STEP_VERIFICATION_IN_PROGRESS, $step);
    }

    public function testReturnsDocumentsSubmittedWhenApplicationIsSubmitted(): void
    {
        $service = new ProviderApprovalStatusService($this->createMock(Connection::class));

        $step = $service->currentStep(
            ['validation_status' => 'pending'],
            ['status' => 'SUBMITTED'],
            ['status' => 'INACTIVE'],
            ['submitted' => true, 'requiredCount' => 2, 'submittedCount' => 1, 'missingTypes' => ['IDENTITY_BACK']],
        );

        self::assertSame(ProviderApprovalStatusService::STEP_DOCUMENTS_SUBMITTED, $step);
    }

    public function testReturnsAccountCreatedWhenOnlyProfileExists(): void
    {
        $service = new ProviderApprovalStatusService($this->createMock(Connection::class));

        $step = $service->currentStep(
            ['validation_status' => 'pending'],
            false,
            false,
            ['submitted' => false, 'requiredCount' => 0, 'submittedCount' => 0, 'missingTypes' => []],
        );

        self::assertSame(ProviderApprovalStatusService::STEP_ACCOUNT_CREATED, $step);
    }
}
