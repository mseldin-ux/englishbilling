<?php

namespace App\Workflows;

use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use Closure;

/** Waits a number of calendar days (or months/years) after the stage is reached. */
class TimerStep extends Step
{
    /**
     * @param  Closure(): int  $days  amount to wait, in $unit
     * @param  'days'|'months'|'years'  $unit
     */
    public function __construct(private string $title, private Closure $days, private string $unit = 'days') {}

    public function title(): string
    {
        return Settings::stage($this->workflow, $this->key)['title'] ?? $this->title;
    }

    public function howCompleted(): string
    {
        $amount = max(0, (int) ($this->days)());
        $unit = $amount === 1 ? rtrim($this->unit, 's') : $this->unit;

        return "Automatic: waits {$amount} {$unit} after the previous stage finishes, then continues by itself. Nobody needs to click anything.";
    }

    public function reach(WorkflowRun $run, WorkflowStep $step): Outcome
    {
        if ($this->unit === 'days') {
            $amount = max(0, (int) ($step->data['days'] ?? ($this->days)()));
            $due = ($step->reached_at ?? now())->addDays($amount);
            $data = ['days' => $amount];
        } else {
            $amount = max(0, (int) ($step->data['amount'] ?? ($this->days)()));
            // No overflow: a wait that starts on Feb 29 ends on Feb 28.
            $due = $this->unit === 'years' ? ($step->reached_at ?? now())->addYearsNoOverflow($amount)
                : ($step->reached_at ?? now())->addMonthsNoOverflow($amount);
            $data = ['amount' => $amount, 'unit' => $this->unit];
        }
        $data['until'] = $due->toIso8601String();

        return $due->isFuture() ? Outcome::scheduled($due, null, $data) : Outcome::done($data);
    }
}
