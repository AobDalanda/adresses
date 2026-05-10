<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;

class SupabaseStorageClient
{
    public function __construct(
        private string $baseUrl,
        private string $serviceRoleKey,
        private int $signedUrlTtlSeconds = 3600
    ) {
    }

    public function upload(UploadedFile $file, string $bucket, string $path, string $mimeType): string
    {
        $response = $this->request(
            'POST',
            sprintf('/storage/v1/object/%s/%s', rawurlencode($bucket), $this->encodePath($path)),
            [
                'Content-Type: ' . $mimeType,
                'x-upsert: false',
            ],
            $this->readUploadedFile($file)
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException($this->extractErrorMessage($response['body'], 'Erreur Supabase lors de l’upload du fichier'));
        }

        return sprintf('supabase://%s/%s', $bucket, ltrim($path, '/'));
    }

    public function deleteIfStored(?string $storageUri): void
    {
        $location = $this->parseStorageUri($storageUri);
        if ($location === null) {
            return;
        }

        $response = $this->request(
            'DELETE',
            sprintf('/storage/v1/object/%s/%s', rawurlencode($location['bucket']), $this->encodePath($location['path']))
        );

        if ($response['status'] === 404) {
            return;
        }

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException($this->extractErrorMessage($response['body'], 'Erreur Supabase lors de la suppression du fichier'));
        }
    }

    public function getPublicUrl(?string $storageUri): ?string
    {
        $location = $this->parseStorageUri($storageUri);
        if ($location === null) {
            return $storageUri;
        }

        return rtrim($this->baseUrl, '/') . sprintf(
            '/storage/v1/object/public/%s/%s',
            rawurlencode($location['bucket']),
            $this->encodePath($location['path'])
        );
    }

    public function createSignedUrl(?string $storageUri, ?int $ttlSeconds = null): ?string
    {
        $location = $this->parseStorageUri($storageUri);
        if ($location === null) {
            return $storageUri;
        }

        $response = $this->request(
            'POST',
            sprintf(
                '/storage/v1/object/sign/%s/%s',
                rawurlencode($location['bucket']),
                $this->encodePath($location['path'])
            ),
            ['Content-Type: application/json'],
            json_encode([
                'expiresIn' => $ttlSeconds ?? $this->signedUrlTtlSeconds,
            ], JSON_THROW_ON_ERROR)
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException($this->extractErrorMessage($response['body'], 'Erreur Supabase lors de la génération de l’URL signée'));
        }

        $payload = json_decode($response['body'], true);
        if (!is_array($payload) || !isset($payload['signedURL']) || !is_string($payload['signedURL'])) {
            throw new \RuntimeException('Réponse Supabase invalide lors de la génération de l’URL signée');
        }

        if (str_starts_with($payload['signedURL'], 'http')) {
            return $payload['signedURL'];
        }

        return rtrim($this->baseUrl, '/') . '/storage/v1' . $payload['signedURL'];
    }

    /**
     * @return array{bucket: string, path: string}|null
     */
    private function parseStorageUri(?string $storageUri): ?array
    {
        if (!is_string($storageUri) || !str_starts_with($storageUri, 'supabase://')) {
            return null;
        }

        $trimmed = substr($storageUri, strlen('supabase://'));
        $parts = explode('/', $trimmed, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }

        return ['bucket' => $parts[0], 'path' => $parts[1]];
    }

    private function encodePath(string $path): string
    {
        return implode('/', array_map(static fn (string $segment): string => rawurlencode($segment), explode('/', ltrim($path, '/'))));
    }

    private function readUploadedFile(UploadedFile $file): string
    {
        $contents = file_get_contents($file->getPathname());
        if ($contents === false) {
            throw new \RuntimeException('Impossible de lire le fichier à envoyer');
        }

        return $contents;
    }

    /**
     * @return array{status: int, body: string}
     */
    private function request(string $method, string $path, array $headers = [], ?string $body = null): array
    {
        if ($this->baseUrl === '' || $this->serviceRoleKey === '') {
            throw new \RuntimeException('Configuration Supabase manquante');
        }

        $ch = curl_init(rtrim($this->baseUrl, '/') . $path);
        if ($ch === false) {
            throw new \RuntimeException('Impossible d’initialiser la requête Supabase');
        }

        $httpHeaders = array_merge([
            'Authorization: Bearer ' . $this->serviceRoleKey,
            'apikey: ' . $this->serviceRoleKey,
        ], $headers);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $httpHeaders);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($responseBody === false) {
            throw new \RuntimeException(sprintf('Erreur réseau Supabase: %s', $error !== '' ? $error : 'inconnue'));
        }

        return [
            'status' => $status,
            'body' => $responseBody,
        ];
    }

    private function extractErrorMessage(string $responseBody, string $fallback): string
    {
        $payload = json_decode($responseBody, true);
        if (!is_array($payload)) {
            return $fallback;
        }

        foreach (['message', 'error', 'msg'] as $field) {
            if (isset($payload[$field]) && is_string($payload[$field]) && trim($payload[$field]) !== '') {
                return $payload[$field];
            }
        }

        return $fallback;
    }
}
