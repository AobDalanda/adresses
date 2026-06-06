<?php

declare(strict_types=1);

namespace App\Tests\Service\Tracking;

use App\Entity\DriverLocation;
use App\Service\Tracking\LocationPublisher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final class LocationPublisherTest extends TestCase
{
    public function testPublishesExpectedMercureTopicAndPayload(): void
    {
        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::once())
            ->method('publish')
            ->with(self::callback(static function (Update $update): bool {
                $payload = json_decode($update->getData(), true, 512, JSON_THROW_ON_ERROR);

                return $update->getTopics() === ['driver/15/location']
                    && $update->isPrivate()
                    && $payload['driverId'] === 15
                    && $payload['accuracy'] === 5.3;
            }))
            ->willReturn('event-id');

        $publisher = new LocationPublisher($hub, new NullLogger());

        self::assertTrue($publisher->publish(
            new DriverLocation(15, 9.6412, -13.5784, 5.3, 18.5, 220.0, 74, 'gps')
        ));
    }

    public function testMercureFailureDoesNotBreakGpsPersistenceFlow(): void
    {
        $hub = $this->createMock(HubInterface::class);
        $hub->method('publish')->willThrowException(new \RuntimeException('Hub unavailable'));

        $publisher = new LocationPublisher($hub, new NullLogger());

        self::assertFalse($publisher->publish(
            new DriverLocation(15, 9.6412, -13.5784, 5.3, null, null, null, 'gps')
        ));
    }
}
