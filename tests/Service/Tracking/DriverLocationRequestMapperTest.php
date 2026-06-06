<?php

declare(strict_types=1);

namespace App\Tests\Service\Tracking;

use App\Service\Tracking\DriverLocationRequestMapper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class DriverLocationRequestMapperTest extends TestCase
{
    private DriverLocationRequestMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new DriverLocationRequestMapper();
    }

    public function testMapsLocationPayloadAndDefaultsSource(): void
    {
        $request = new Request(content: json_encode([
            'driverId' => 15,
            'latitude' => 9.6412,
            'longitude' => -13.5784,
            'accuracy' => 5.3,
            'speed' => 18.5,
            'heading' => 220,
            'batteryLevel' => 74,
        ], JSON_THROW_ON_ERROR));

        $input = $this->mapper->mapLocation($request);

        self::assertSame(15, $input->driverId);
        self::assertSame(9.6412, $input->latitude);
        self::assertSame('gps', $input->source);
    }

    public function testRejectsInvalidNumericField(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->mapper->mapLocation(new Request(content: '{"driverId":15,"latitude":"x"}'));
    }

    public function testMapsDateOnlyToInclusiveEndOfDay(): void
    {
        $query = $this->mapper->mapHistory(new Request(query: [
            'from' => '2026-06-01',
            'to' => '2026-06-05',
            'limit' => '100',
        ]));

        self::assertSame('2026-06-05 23:59:59.999999', $query->to?->format('Y-m-d H:i:s.u'));
        self::assertSame(100, $query->limit);
    }
}
