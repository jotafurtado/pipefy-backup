<?php

namespace App\Backup;

class VerifyCardBackupResult
{
    /**
     * @param  list<string>  $issues
     */
    public function __construct(
        public readonly bool $ok,
        public readonly array $issues,
    ) {}
}
