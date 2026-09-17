<?php

namespace App\Backup;

class RetryFailedCardsReport
{
    /**
     * @param  list<RetryFailedCardItem>  $items
     */
    public function __construct(
        public readonly int $count,
        public readonly array $items,
    ) {}

    public function isEmpty(): bool
    {
        return $this->count === 0;
    }
}
