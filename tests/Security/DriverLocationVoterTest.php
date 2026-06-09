<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\DriverLocationVoter;
use App\Security\TrackingIdentity;
use PHPUnit\Framework\TestCase;

final class DriverLocationVoterTest extends TestCase
{
    private DriverLocationVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new DriverLocationVoter();
    }

    public function testDriverCanPublishAndViewOwnLocation(): void
    {
        $identity = new TrackingIdentity(15, 'livreur', []);

        self::assertTrue($this->voter->canAccess(DriverLocationVoter::PUBLISH, $identity, 15));
        self::assertTrue($this->voter->canAccess(DriverLocationVoter::VIEW, $identity, 15));
    }

    public function testDriverCannotAccessAnotherDriver(): void
    {
        $identity = new TrackingIdentity(15, 'livreur', []);

        self::assertFalse($this->voter->canAccess(DriverLocationVoter::VIEW, $identity, 16));
    }

    public function testAdminCanViewButCannotPublishForDriver(): void
    {
        $identity = new TrackingIdentity(1, 'admin', ['ROLE_ADMIN']);

        self::assertTrue($this->voter->canAccess(DriverLocationVoter::VIEW, $identity, 15));
        self::assertFalse($this->voter->canAccess(DriverLocationVoter::PUBLISH, $identity, 15));
    }

    public function testApprovedDeliveryProviderCanPublishOwnLocation(): void
    {
        $identity = new TrackingIdentity(15, 'provider', [], true, true);

        self::assertTrue($this->voter->canAccess(DriverLocationVoter::PUBLISH, $identity, 15));
    }

    public function testPendingOrTransportOnlyProviderCannotPublishLocation(): void
    {
        $pending = new TrackingIdentity(15, 'provider', [], true, false);
        $transportOnly = new TrackingIdentity(15, 'provider', [], false, true);

        self::assertFalse($this->voter->canAccess(DriverLocationVoter::PUBLISH, $pending, 15));
        self::assertFalse($this->voter->canAccess(DriverLocationVoter::PUBLISH, $transportOnly, 15));
    }
}
