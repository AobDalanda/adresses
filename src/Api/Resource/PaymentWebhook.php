<?php

namespace App\Api\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use App\Api\Controller\PaymentWebhookAction;

#[ApiResource(operations: [
    new Post(
        uriTemplate: '/payment/webhook/{provider}',
        uriVariables: [
            'provider' => new Link(fromClass: PaymentWebhook::class, identifiers: ['provider']),
        ],
        controller: PaymentWebhookAction::class,
        read: false,
        deserialize: false,
        output: false,
        name: 'app_paymentwebhook_webhook'
    ),
])]
final class PaymentWebhook
{
    #[ApiProperty(identifier: true)]
    public string $provider;
}
