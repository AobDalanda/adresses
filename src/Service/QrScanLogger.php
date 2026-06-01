<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

final class QrScanLogger
{
    public function __construct(private Connection $db)
    {
    }

    /**
     * @param array{
     *     token: ?string,
     *     ip: ?string,
     *     user_agent: ?string,
     *     user_id: ?int,
     *     device: ?string,
     *     country: ?string,
     *     city: ?string,
     *     latitude: ?float,
     *     longitude: ?float,
     *     status: string,
     *     error_message: ?string
     * } $context
     */
    public function log(array $context): void
    {
        $this->db->insert('qr_scan_logs', [
            'token' => $context['token'],
            'scanned_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'ip_address' => $context['ip'],
            'user_agent' => $context['user_agent'],
            'authenticated_user_id' => $context['user_id'],
            'device' => $context['device'],
            'country' => $context['country'],
            'city' => $context['city'],
            'latitude' => $context['latitude'],
            'longitude' => $context['longitude'],
            'status' => $context['status'],
            'error_message' => $context['error_message'],
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }
}
