<?php

namespace App\Service;

class UserAccountAssetUrlResolver
{
    public function __construct(
        private SupabaseStorageClient $storage,
        private UserCapabilityService $capabilities
    ) {
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function enrich(array $user): array
    {
        if (isset($user['id'])) {
            $userId = (int) $user['id'];
            $storedAccountType = is_string($user['accountType'] ?? null) ? $user['accountType'] : 'client';
            $capabilities = $this->capabilities->forUser($userId);
            $user['capabilities'] = $capabilities;
            // Kept for deployed mobile clients; new clients should use capabilities.provider.
            $user['accountType'] = $storedAccountType === 'admin'
                ? 'admin'
                : ($capabilities['provider'] ? 'provider' : 'client');
        }

        $profilePhotoUrl = $this->resolvePublicUrl($this->stringOrNull($user['profilePhotoPath'] ?? null));

        $user['profilePhotoUrl'] = $profilePhotoUrl;
        $user['profilePhoto'] = $profilePhotoUrl;
        $user['profile_photo_url'] = $profilePhotoUrl;
        $user['identityDocumentUrl'] = $this->resolveSignedUrl($this->stringOrNull($user['identityDocumentPath'] ?? null));
        $user['driverLicenseUrl'] = $this->resolveSignedUrl($this->stringOrNull($user['driverLicensePath'] ?? null));

        unset($user['profilePhotoPath'], $user['identityDocumentPath'], $user['driverLicensePath']);

        return $user;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private function resolvePublicUrl(?string $path): ?string
    {
        try {
            return $this->storage->getPublicUrl($path);
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveSignedUrl(?string $path): ?string
    {
        try {
            return $this->storage->createSignedUrl($path);
        } catch (\Throwable) {
            return null;
        }
    }
}
