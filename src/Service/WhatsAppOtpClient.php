<?php

namespace App\Service;

use App\Util\PhoneNumberNormalizer;

class WhatsAppOtpClient
{
    private const REQUEST_TIMEOUT_SECONDS = 15;

    public function __construct(
        private string $baseUrl,
        private string $apiKey,
        private string $instance,
        private int $delayMs = 1000
    ) {
    }

    public function sendOtp(string $phone, string $otp): void
    {
        $number = $this->normalizePhone($phone);
        if ($number === '') {
            throw new \InvalidArgumentException('Phone number is required for WhatsApp OTP');
        }

        $connectionState = $this->request(
            'GET',
            sprintf(
                '%s/instance/connectionState/%s',
                rtrim($this->baseUrl, '/'),
                rawurlencode($this->instance)
            )
        );
        $state = $connectionState['instance']['state'] ?? null;
        if ($state !== 'open') {
            throw new \RuntimeException(sprintf('Evolution instance "%s" is not connected to WhatsApp', $this->instance));
        }

        $payload = json_encode([
            'number' => $number,
            'text' => sprintf('Votre code OTP Adressage est : %s. Ce code expire dans 5 minutes.', $otp),
            'delay' => $this->delayMs,
            'linkPreview' => false,
        ], JSON_THROW_ON_ERROR);

        $this->request(
            'POST',
            sprintf(
                '%s/message/sendText/%s',
                rtrim($this->baseUrl, '/'),
                rawurlencode($this->instance)
            ),
            $payload
        );
    }

    private function normalizePhone(string $phone): string
    {
        return PhoneNumberNormalizer::normalize($phone);
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $method, string $url, ?string $payload = null): array
    {
        $ch = curl_init();
        if ($ch === false) {
            throw new \RuntimeException('Unable to initialize Evolution API request');
        }

        $headers = ['apikey: ' . $this->apiKey];
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::REQUEST_TIMEOUT_SECONDS);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::REQUEST_TIMEOUT_SECONDS);

        if ($method === 'POST') {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $status >= 400) {
            throw new \RuntimeException(sprintf('Evolution API request failed: %s', $error ?: (string) $response));
        }

        $decoded = json_decode((string) $response, true);

        return is_array($decoded) ? $decoded : [];
    }
}
