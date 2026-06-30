<?php

declare(strict_types=1);

namespace App\Tests\Service\Tracking;

use App\Service\Tracking\DeliveryLocationPublisher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final class DeliveryLocationPublisherTest extends TestCase
{
    public function testPublishesPrivateDeliveryTopic(): void
    {
        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::once())
            ->method('publish')
            ->with(self::callback(static function (Update $update): bool {
                return $update->getTopics() === ['delivery/delivery-42/location']
                    && $update->isPrivate();
            }));

        (new DeliveryLocationPublisher($hub))->publish([
            'deliveryId' => 'delivery-42',
            'driverId' => 15,
            'latitude' => 9.6412,
            'longitude' => -13.5784,
        ]);
    }
}
