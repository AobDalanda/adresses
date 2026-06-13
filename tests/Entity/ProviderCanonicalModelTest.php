<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\OutboxEvent;
use App\Entity\ProviderApplication;
use App\Entity\ProviderApplicationRevision;
use App\Entity\ProviderAuthorization;
use App\Entity\ProviderDecisionHistory;
use App\Entity\ProviderProfile;
use App\Entity\UserAccount;
use App\Enum\ProviderActivity;
use App\Enum\ProviderApplicationStatus;
use App\Enum\ProviderAuthorizationStatus;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class ProviderCanonicalModelTest extends TestCase
{
    public function testApplicationStartsAsAnUnsubmittedDraft(): void
    {
        $profile = $this->createProviderProfile();
        $application = new ProviderApplication($profile);

        self::assertTrue(Uuid::isValid($application->getPublicId()));
        self::assertSame($profile, $application->getProviderProfile());
        self::assertSame(ProviderApplicationStatus::DRAFT, $application->getStatus());
        self::assertNull($application->getCurrentRevision());
        self::assertNull($application->getApprovedRevision());
        self::assertNull($application->getLegacyDriverApplicationId());
        self::assertNull($application->getSubmittedAt());
        self::assertNull($application->getDecidedAt());
        self::assertSame(1, $application->getLockVersion());
    }

    public function testRevisionCapturesAnImmutableSnapshot(): void
    {
        $user = new UserAccount();
        $application = new ProviderApplication(new ProviderProfile($user, true, false));
        $revision = new ProviderApplicationRevision(
            $application,
            1,
            [ProviderActivity::DELIVERY],
            ['displayName' => 'Provider One'],
            $user,
        );

        self::assertSame($application, $revision->getApplication());
        self::assertSame(1, $revision->getVersion());
        self::assertSame([ProviderActivity::DELIVERY], $revision->getActivities());
        self::assertSame(['displayName' => 'Provider One'], $revision->getProfileData());
        self::assertSame($user, $revision->getCreatedBy());
        self::assertNull($revision->getSupersedesRevision());
        self::assertNull($revision->getSubmittedAt());
    }

    public function testRevisionRejectsInvalidInitialContent(): void
    {
        $user = new UserAccount();
        $application = new ProviderApplication(new ProviderProfile($user, true, false));

        $this->expectException(\InvalidArgumentException::class);

        new ProviderApplicationRevision($application, 0, [], [], $user);
    }

    public function testDecisionCapturesCorrelationAndStateChange(): void
    {
        $application = new ProviderApplication($this->createProviderProfile());
        $decision = new ProviderDecisionHistory(
            $application,
            null,
            'SUBMIT',
            ProviderApplicationStatus::DRAFT,
            ProviderApplicationStatus::SUBMITTED,
            'PROVIDER',
            42,
            correlationId: '01975aa9-df9c-7b25-b797-6b1ca912e68e',
        );

        self::assertSame('SUBMIT', $decision->getTransition());
        self::assertSame(ProviderApplicationStatus::DRAFT, $decision->getOldStatus());
        self::assertSame(ProviderApplicationStatus::SUBMITTED, $decision->getNewStatus());
        self::assertSame('PROVIDER', $decision->getActorType());
        self::assertSame(42, $decision->getActorId());
        self::assertSame('01975aa9-df9c-7b25-b797-6b1ca912e68e', $decision->getCorrelationId());
    }

    public function testAuthorizationStartsInactiveWithoutGrantedActivities(): void
    {
        $authorization = new ProviderAuthorization($this->createProviderProfile());

        self::assertSame(ProviderAuthorizationStatus::INACTIVE, $authorization->getStatus());
        self::assertFalse($authorization->canDeliver());
        self::assertFalse($authorization->canTransportPeople());
        self::assertNull($authorization->getSourceApplication());
        self::assertNull($authorization->getSourceRevision());
        self::assertSame(1, $authorization->getLockVersion());
    }

    public function testOutboxEventStartsUnpublishedAndUnattempted(): void
    {
        $event = new OutboxEvent(
            'provider_application',
            '01975aa9-df9c-7b25-b797-6b1ca912e68e',
            'provider.application.submitted',
            ['revision' => 1],
        );

        self::assertTrue(Uuid::isValid($event->getId()));
        self::assertSame('provider_application', $event->getAggregateType());
        self::assertSame('provider.application.submitted', $event->getEventName());
        self::assertSame(['revision' => 1], $event->getPayload());
        self::assertNull($event->getPublishedAt());
        self::assertSame(0, $event->getAttempts());
        self::assertNull($event->getLastError());
    }

    private function createProviderProfile(): ProviderProfile
    {
        return new ProviderProfile(new UserAccount(), true, false);
    }
}
