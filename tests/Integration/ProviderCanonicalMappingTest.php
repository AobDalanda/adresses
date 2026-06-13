<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\OutboxEvent;
use App\Entity\ProviderApplication;
use App\Entity\ProviderApplicationRevision;
use App\Entity\ProviderAutomaticCheck;
use App\Entity\ProviderAuthorization;
use App\Entity\ProviderDecisionHistory;
use App\Entity\ProviderDocument;
use App\Enum\ProviderApplicationStatus;
use App\Enum\ProviderAuthorizationStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ProviderCanonicalMappingTest extends KernelTestCase
{
    public function testCanonicalProviderTablesAreMapped(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $expectedTables = [
            ProviderApplication::class => 'provider_application',
            ProviderApplicationRevision::class => 'provider_application_revision',
            ProviderAutomaticCheck::class => 'provider_automatic_check',
            ProviderDecisionHistory::class => 'provider_decision_history',
            ProviderDocument::class => 'provider_document',
            ProviderAuthorization::class => 'provider_authorization',
            OutboxEvent::class => 'outbox_event',
        ];

        foreach ($expectedTables as $entityClass => $tableName) {
            self::assertSame($tableName, $entityManager->getClassMetadata($entityClass)->getTableName());
        }
    }

    public function testApplicationAndAuthorizationUseOptimisticLockingAndBackedEnums(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $application = $entityManager->getClassMetadata(ProviderApplication::class);
        self::assertTrue($application->isVersioned);
        self::assertSame('lockVersion', $application->versionField);
        self::assertSame(
            ProviderApplicationStatus::class,
            $application->getFieldMapping('status')->enumType,
        );

        $authorization = $entityManager->getClassMetadata(ProviderAuthorization::class);
        self::assertTrue($authorization->isVersioned);
        self::assertSame('lockVersion', $authorization->versionField);
        self::assertSame(
            ProviderAuthorizationStatus::class,
            $authorization->getFieldMapping('status')->enumType,
        );
    }

    public function testSnapshotsAndEventsUseJsonbColumns(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $revision = $entityManager->getClassMetadata(ProviderApplicationRevision::class);
        self::assertTrue($revision->getFieldMapping('activities')->options['jsonb']);
        self::assertTrue($revision->getFieldMapping('profileData')->options['jsonb']);

        $decision = $entityManager->getClassMetadata(ProviderDecisionHistory::class);
        self::assertTrue($decision->getFieldMapping('affectedItems')->options['jsonb']);
        self::assertTrue($decision->getFieldMapping('metadata')->options['jsonb']);

        $outbox = $entityManager->getClassMetadata(OutboxEvent::class);
        self::assertTrue($outbox->getFieldMapping('payload')->options['jsonb']);
    }
}
