<?php

namespace App\Workflows;

use App\Models\WorkflowRun;
use App\Models\WorkflowStep;

/**
 * One stage of a workflow. Stages run in order; a run waits at the first stage that is not finished.
 */
abstract class Step
{
    /** Who the run is waiting on while this stage is open. */
    public const WAITING_ON = ['system' => 'System', 'learner' => 'Adult Learner', 'director' => 'Level Director',
        'finance' => 'Finance', 'records' => 'Records staff'];

    /** Set by Workflows when the stage is resolved, for settings lookups. */
    public string $workflow = '';

    public string $key = '';

    abstract public function title(): string;

    /**
     * Performs or checks the stage. Throw StepFailed for problems staff must fix; any other
     * exception is treated as temporary and retried automatically.
     */
    abstract public function reach(WorkflowRun $run, WorkflowStep $step): Outcome;

    public function waitingOn(): string
    {
        return 'system';
    }

    /** A failed optional stage does not hold up the stages after it. */
    public function required(): bool
    {
        return true;
    }

    /** Whether a waiting stage should be checked again on every scheduler pass. */
    public function polls(): bool
    {
        return false;
    }

    /** Information shown with the stage on the run page, as label => value (or [value, url]). */
    public function context(WorkflowRun $run): array
    {
        return [];
    }

    /** How the stage gets finished, for the stage guide. */
    public function howCompleted(): string
    {
        return 'Automatic: the system completes this stage by itself.';
    }

    /** Editable texts this stage uses. @return array<int, TemplateDefinition> */
    public function templates(): array
    {
        return [];
    }
}
