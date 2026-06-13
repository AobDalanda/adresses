<?php

namespace App\Service;

use Kreait\Firebase\Contract\Messaging as MessagingContract;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;

final class FcmPushClient
{
    private ?MessagingContract $messaging = null;

    public function __construct(
        private string $credentialsPath
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
        $message = CloudMessage::withTarget('token', $fcmToken)
            ->withNotification(Notification::create($title, $body))
            ->withData($data);

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
