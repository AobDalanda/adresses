<?php

namespace App\Service;

use Kreait\Firebase\Contract\Messaging as MessagingContract;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;

final class FcmPushClient implements PushClientInterface
{
    public function __construct(
        private string $credentialsPath,
        private ?MessagingContract $messaging = null,
    ) {
    }

    public function sendOtp(string $fcmToken, string $otp): void
    {
        $this->send($fcmToken, 'Code OTP', sprintf('Votre code OTP est: %s', $otp), [
            'type' => 'otp',
            'otp' => $otp,
        ]);
    }

    /**
     * @param array<string, string> $data
     */
    public function send(string $fcmToken, string $title, string $body, array $data = []): void
    {
        $message = CloudMessage::new()
            ->toToken($fcmToken)
            ->withNotification(Notification::create($title, $body))
            ->withData($data);

        $androidConfig = [];
        $collapseKey = $data['collapseKey'] ?? null;
        if (is_string($collapseKey) && $collapseKey !== '') {
            $androidConfig['collapse_key'] = $collapseKey;
            $message = $message->withApnsConfig(['headers' => ['apns-collapse-id' => $collapseKey]]);
        }

        $androidNotification = [];
        $icon = $data['notificationIcon'] ?? null;
        if (is_string($icon) && preg_match('/^[a-z][a-z0-9_]*$/', $icon) === 1) {
            $androidNotification['icon'] = $icon;
        }

        $color = $data['notificationColor'] ?? null;
        if (is_string($color) && preg_match('/^#[0-9A-Fa-f]{6}$/', $color) === 1) {
            $androidNotification['color'] = strtoupper($color);
        }

        if ($androidNotification !== []) {
            $androidConfig['notification'] = $androidNotification;
        }

        if ($androidConfig !== []) {
            $message = $message->withAndroidConfig($androidConfig);
        }

        try {
            $this->getMessaging()->send($message);
        } catch (\Throwable $e) {
            throw new \RuntimeException('FCM send failed: ' . $e->getMessage(), 0, $e);
        }
    }

    private function getMessaging(): MessagingContract
    {
        if ($this->messaging !== null) {
            return $this->messaging;
        }

        if ($this->credentialsPath === '' || !is_file($this->credentialsPath)) {
            throw new \RuntimeException(sprintf('Firebase credentials file not found: %s', $this->credentialsPath));
        }

        $factory = (new Factory())->withServiceAccount($this->credentialsPath);
        $this->messaging = $factory->createMessaging();

        return $this->messaging;
    }
}
