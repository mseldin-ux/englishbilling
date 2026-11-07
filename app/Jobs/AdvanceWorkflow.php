<?php

namespace App\Jobs;

use App\Workflows\Workflows;
use Illuminate\Foundation\Bus\Dispatchable;

class AdvanceWorkflow
{
    use Dispatchable;

    public function __construct(public int $runId) {}

    public function handle(): void
    {
        Workflows::advance($this->runId);
    }
}
