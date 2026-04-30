<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class EvolutionQrController extends AbstractController
{
    public function __construct(private ParameterBagInterface $parameters)
    {
    }

    #[Route('/dev/evolution/qr', name: 'dev_evolution_qr', methods: ['GET'])]
    public function show(): Response
    {
        $baseUrl = rtrim((string) $this->parameters->get('evolution_api_base_url'), '/');
        $apiKey = (string) $this->parameters->get('evolution_api_key');
        $instance = (string) $this->parameters->get('evolution_api_instance');

        $payload = $this->requestQrCode($baseUrl, $apiKey, $instance);
        $base64 = is_array($payload) && isset($payload['base64']) ? (string) $payload['base64'] : '';
        $code = is_array($payload) && isset($payload['code']) ? (string) $payload['code'] : '';
        $state = is_array($payload) && isset($payload['instance']['state']) ? (string) $payload['instance']['state'] : '';
        $error = is_array($payload) && isset($payload['error']) ? (string) $payload['error'] : '';

        $html = <<<'HTML'
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>QR WhatsApp Evolution</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; font-family: Arial, sans-serif; background: #f6f7f9; color: #14171a; }
        main { width: min(92vw, 560px); padding: 28px; background: white; border: 1px solid #d9dee6; border-radius: 8px; box-shadow: 0 12px 30px rgba(15, 23, 42, .08); }
        h1 { margin: 0 0 16px; font-size: 24px; }
        p { line-height: 1.5; }
        img { display: block; width: min(100%, 360px); height: auto; margin: 20px auto; border: 1px solid #e3e7ee; }
        code, textarea { width: 100%; box-sizing: border-box; }
        textarea { min-height: 110px; padding: 12px; border: 1px solid #d9dee6; border-radius: 6px; font-family: monospace; }
        .error { color: #b42318; }
    </style>
</head>
<body>
<main>
    <h1>QR WhatsApp Evolution</h1>
    {{ body }}
</main>
</body>
</html>
HTML;

        $html = str_replace('{{ body }}', $this->renderBody($instance, $base64, $code, $state, $error), $html);

        return new Response($html);
    }

    /**
     * @return array<string, mixed>
     */
    private function requestQrCode(string $baseUrl, string $apiKey, string $instance): array
    {
        $ch = curl_init(sprintf('%s/instance/connect/%s', $baseUrl, rawurlencode($instance)));
        if ($ch === false) {
            return ['error' => 'Impossible d’initialiser la requête vers Evolution API.'];
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['apikey: ' . $apiKey]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $status >= 400) {
            return ['error' => $error ?: (string) $response];
        }

        $data = json_decode((string) $response, true);
        return is_array($data) ? $data : ['error' => 'Réponse Evolution API invalide.'];
    }

    private function renderBody(string $instance, string $base64, string $code, string $state, string $error): string
    {
        if ($state === 'open') {
            return sprintf(
                '<p>L’instance <strong>%s</strong> est connectée à WhatsApp.</p><p>Tu peux maintenant envoyer les messages depuis le backend Symfony.</p>',
                htmlspecialchars($instance, ENT_QUOTES)
            );
        }

        if ($base64 !== '') {
            return sprintf(
                '<p>Scanne ce QR code avec WhatsApp pour connecter l’instance <strong>%s</strong>.</p><img src="%s" alt="QR code WhatsApp"><textarea readonly>%s</textarea>',
                htmlspecialchars($instance, ENT_QUOTES),
                htmlspecialchars($base64, ENT_QUOTES),
                htmlspecialchars($code, ENT_QUOTES)
            );
        }

        if ($error !== '') {
            return sprintf('<p class="error">%s</p>', htmlspecialchars($error, ENT_QUOTES));
        }

        return '<p class="error">Evolution API n’a pas retourné de QR code. Recharge la page dans quelques secondes.</p>';
    }
}
