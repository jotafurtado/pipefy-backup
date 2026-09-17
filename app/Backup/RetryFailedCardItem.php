<?php

namespace App\Backup;

class RetryFailedCardItem
{
    public function __construct(
        public readonly int $pipeId,
        public readonly string $pipeName,
        public readonly int $cardId,
        public readonly ?string $cardTitle,
    ) {}
}
