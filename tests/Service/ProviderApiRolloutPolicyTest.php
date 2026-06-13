<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ProviderApiRolloutPolicy;
use PHPUnit\Framework\TestCase;

final class ProviderApiRolloutPolicyTest extends TestCase
{
    public function testDisabledCanonicalReadsForceV1(): void
    {
        $policy = new ProviderApiRolloutPolicy(false, 100, 'salt', null);

        self::assertFalse($policy->shouldUseV2(42));
        self::assertSame(0, $policy->rolloutPercent());
    }

    public function testFullRolloutForcesV2(): void
    {
        $policy = new ProviderApiRolloutPolicy(true, 100, 'salt', null);

        self::assertTrue($policy->shouldUseV2(42));
        self::assertSame(100, $policy->rolloutPercent());
    }

    public function testCohortAssignmentIsStable(): void
    {
        $policy = new ProviderApiRolloutPolicy(true, 50, 'stable-salt', null);

        self::assertSame($policy->shouldUseV2(42), $policy->shouldUseV2(42));
    }

    public function testSunsetIsNormalizedAsHttpDate(): void
    {
        $policy = new ProviderApiRolloutPolicy(true, 10, 'salt', '2027-06-30 00:00:00 UTC');

        self::assertSame('Wed, 30 Jun 2027 00:00:00 GMT', $policy->sunsetHttpDate());
    }

    public function testInvalidPercentageIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ProviderApiRolloutPolicy(true, 101, 'salt', null);
    }
}
