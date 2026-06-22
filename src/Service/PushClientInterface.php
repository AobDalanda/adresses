<?php

declare(strict_types=1);

namespace App\Service;

interface PushClientInterface
{
    /**
     * @param array<string, string> $data
     */
    public function send(string $fcmToken, string $title, string $body, array $data = []): void;
}
