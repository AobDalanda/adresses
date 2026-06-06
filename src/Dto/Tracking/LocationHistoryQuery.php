<?php

declare(strict_types=1);

namespace App\Dto\Tracking;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class LocationHistoryQuery
{
    public function __construct(
        public ?\DateTimeImmutable $from,
        public ?\DateTimeImmutable $to,
        #[Assert\Range(min: 1, max: 1000)]
        public int $limit = 100
    ) {
    }
}
