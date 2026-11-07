<?php

namespace App\Workflows;

use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use Closure;

/** Waits until something happens elsewhere in the system, such as a form being submitted. */
class AwaitStep extends Step
{
    /** @param  Closure(WorkflowRun): ?array  $check  returns stage data once satisfied, otherwise null */
    public function __construct(
        private string $title,
        private string $waitingOn,
        private Closure $check,
        private ?Closure $context = null,
    ) {}

    public function title(): string
    {
        return Settings::stage($this->workflow, $this->key)['title'] ?? $this->title;
    }

    public function waitingOn(): string
    {
        return $this->waitingOn;
    }

    public function howCompleted(): string
    {
        return 'Automatic: nobody clicks anything here. The stage finishes by itself as soon as the '
            .(Step::WAITING_ON[$this->waitingOn] ?? $this->waitingOn).' submits, and the workflow moves on.';
    }

    public function polls(): bool
    {
        return true;
    }

    public function reach(WorkflowRun $run, WorkflowStep $step): Outcome
    {
        $data = ($this->check)($run);

        return $data === null ? Outcome::waiting() : Outcome::done($data);
    }

    public function context(WorkflowRun $run): array
    {
        return $this->context ? ($this->context)($run) : [];
    }
}
