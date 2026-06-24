<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\FcmPushClient;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use PHPUnit\Framework\TestCase;

final class FcmPushClientTest extends TestCase
{
    public function testSendsMessageWithTokenPayloadAndCollapseConfiguration(): void
    {
        $messaging = $this->createMock(Messaging::class);
        $messaging->expects(self::once())
            ->method('send')
            ->with(self::callback(static function (CloudMessage $message): bool {
                $payload = $message->jsonSerialize();

                return ($payload['token'] ?? null) === 'fcm-token'
                    && ($payload['notification']['title'] ?? null) === 'Nouvelle livraison'
                    && ($payload['notification']['body'] ?? null) === 'Une nouvelle livraison est disponible.'
                    && ($payload['data']['type'] ?? null) === 'delivery_order.created'
                    && ($payload['android']['collapse_key'] ?? null) === 'delivery_order.delivery-id'
                    && ($payload['apns']['headers']['apns-collapse-id'] ?? null) === 'delivery_order.delivery-id';
            }));

        (new FcmPushClient('', $messaging))->send(
            'fcm-token',
            'Nouvelle livraison',
            'Une nouvelle livraison est disponible.',
            [
                'type' => 'delivery_order.created',
                'collapseKey' => 'delivery_order.delivery-id',
            ],
        );
    }
}
