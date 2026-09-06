<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessImport implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<int, array<string, mixed>>  $offers
     */
    public function __construct(
        public int $importId,
        public array $offers,
    ) {}

    /**
     * Apply the imported offers and settle the import's status.
     */
    public function handle(): void
    {
        //
    }
}
