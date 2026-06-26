<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\WhatsAppOtpClient;
use PHPUnit\Framework\TestCase;

final class WhatsAppOtpClientTest extends TestCase
{
    public function testBuildsAldahimOtpMessage(): void
    {
        $client = new WhatsAppOtpClient('https://evolution.example', 'api-key', 'aldahim');
        $method = new \ReflectionMethod($client, 'buildOtpMessage');

        self::assertSame(
            "🔐 Votre code de vérification OTP Aldahim  est : 134899.\n\nCe code est valide pendant 5 minutes.\n\nPour votre sécurité, ne le partagez avec personne. Si vous n'avez pas demandé ce code, ignorez simplement ce message.",
            $method->invoke($client, '134899'),
        );
    }
}
