<?php

namespace App\Api\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use App\Api\Controller\SubscriptionPaymentWebhookAction;

#[ApiResource(operations: [
    new Post(
        uriTemplate: '/webhooks/payments/{provider}',
        uriVariables: [
            'provider' => new Link(fromClass: PaymentProviderWebhook::class, identifiers: ['provider']),
        ],
        controller: SubscriptionPaymentWebhookAction::class,
        read: false,
        deserialize: false,
        output: false,
        name: 'app_subscription_payment_webhook'
    ),
])]
final class PaymentProviderWebhook
{
    #[ApiProperty(identifier: true)]
    public string $provider;
}
