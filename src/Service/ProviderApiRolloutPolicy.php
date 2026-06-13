<?php

declare(strict_types=1);

namespace App\Service;

final class ProviderApiRolloutPolicy
{
    private ?string $sunsetHttpDate;

    public function __construct(
        private readonly bool $canonicalReadEnabled,
        private readonly int $rolloutPercent,
        private readonly string $rolloutSalt,
        ?string $sunset,
    ) {
        if ($rolloutPercent < 0 || $rolloutPercent > 100) {
            throw new \InvalidArgumentException('PROVIDER_V2_ROLLOUT_PERCENT doit etre compris entre 0 et 100.');
        }

        $sunset = is_string($sunset) ? trim($sunset) : '';
        $this->sunsetHttpDate = $sunset === '' ? null : $this->parseSunset($sunset);
    }

    public function shouldUseV2(int $userId): bool
    {
        if (!$this->canonicalReadEnabled || $this->rolloutPercent === 0) {
            return false;
        }
        if ($this->rolloutPercent === 100) {
            return true;
        }

        $bucket = hexdec(substr(hash('sha256', $this->rolloutSalt.':'.$userId), 0, 8)) % 100;

        return $bucket < $this->rolloutPercent;
    }

    public function rolloutPercent(): int
    {
        return $this->canonicalReadEnabled ? $this->rolloutPercent : 0;
    }

    public function sunsetHttpDate(): ?string
    {
        return $this->sunsetHttpDate;
    }

    private function parseSunset(string $sunset): string
    {
        try {
            $date = new \DateTimeImmutable($sunset);
        } catch (\Throwable $exception) {
            throw new \InvalidArgumentException('PROVIDER_V1_SUNSET doit contenir une date valide.', 0, $exception);
        }

        return $date->setTimezone(new \DateTimeZone('GMT'))->format('D, d M Y H:i:s \G\M\T');
    }
}
