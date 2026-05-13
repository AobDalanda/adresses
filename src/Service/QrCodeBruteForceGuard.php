<?php

namespace App\Service;

use Psr\Cache\CacheItemPoolInterface;

final class QrCodeBruteForceGuard
{
    private int $blockTimeSeconds;

    public function __construct(
        private CacheItemPoolInterface $cache,
        private int $maxAttempts = 20,
        string $blockTime = '15 minutes'
    ) {
        $this->blockTimeSeconds = $this->normalizeDurationToSeconds($blockTime);
    }

    public function isBlocked(string $ip, string $scope = 'scan'): bool
    {
        $item = $this->cache->getItem($this->buildBlockedKey($ip, $scope));
        if (!$item->isHit()) {
            return false;
        }

        $blockedUntil = $item->get();
        if (!is_int($blockedUntil) || $blockedUntil < time()) {
            $this->cache->deleteItem($item->getKey());

            return false;
        }

        return true;
    }

    public function registerInvalidAttempt(string $ip, string $scope = 'scan'): void
    {
        $attemptsKey = $this->buildAttemptsKey($ip, $scope);
        $attemptsItem = $this->cache->getItem($attemptsKey);
        $attempts = $attemptsItem->isHit() ? (int) $attemptsItem->get() : 0;
        $attempts++;

        $attemptsItem->set($attempts);
        $attemptsItem->expiresAfter($this->blockTimeSeconds);
        $this->cache->save($attemptsItem);

        if ($attempts < $this->maxAttempts) {
            return;
        }

        $blockedItem = $this->cache->getItem($this->buildBlockedKey($ip, $scope));
        $blockedItem->set(time() + $this->blockTimeSeconds);
        $blockedItem->expiresAfter($this->blockTimeSeconds);
        $this->cache->save($blockedItem);
    }

    public function clear(string $ip, string $scope = 'scan'): void
    {
        $this->cache->deleteItems([
            $this->buildAttemptsKey($ip, $scope),
            $this->buildBlockedKey($ip, $scope),
        ]);
    }

    private function buildAttemptsKey(string $ip, string $scope): string
    {
        return 'qr_attempts_'.$scope.'_'.sha1($ip);
    }

    private function buildBlockedKey(string $ip, string $scope): string
    {
        return 'qr_blocked_'.$scope.'_'.sha1($ip);
    }

    private function normalizeDurationToSeconds(string $duration): int
    {
        $now = new \DateTimeImmutable();
        $target = $now->modify(sprintf('+%s', trim($duration)));

        if (!$target instanceof \DateTimeImmutable) {
            return 900;
        }

        return max(60, $target->getTimestamp() - $now->getTimestamp());
    }
}
