<?php

namespace App\Workflows\Steps;

use App\Billing\EditLocks;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Workflows\Outcome;
use App\Workflows\Settings;
use App\Workflows\Step;
use Illuminate\Support\Facades\DB;

/**
 * Sets the learner's status to Graduated with today's date. If someone has the learner's
 * record open for editing, it waits and tries again rather than overwrite their work.
 */
class MarkGraduated extends Step
{
    public function title(): string
    {
        return Settings::stage($this->workflow, $this->key)['title'] ?? 'Mark the Adult Learner as Graduated';
    }

    public function howCompleted(): string
    {
        return 'Automatic: sets the learner to Graduated and fills in the actual graduation date (today) on the student record. '
            .'If someone has the record open for editing, it waits and tries again every 5 minutes.';
    }

    public function reach(WorkflowRun $run, WorkflowStep $step): Outcome
    {
        $studentId = Graduation::for($run)->student->id;

        return DB::transaction(function () use ($studentId) {
            $lock = EditLocks::current($studentId);
            if ($lock !== null) {
                return Outcome::scheduled(now()->addMinutes(5),
                    "Waiting: {$lock->name} ({$lock->username}) has this learner's record open for editing. Retrying automatically.");
            }
            $before = DB::table('students')->where('id', $studentId)->lockForUpdate()->first(['graduated', 'actualdategrad']);
            $today = now()->startOfDay();
            DB::table('students')->where('id', $studentId)->update(['graduated' => 1, 'actualdategrad' => $today->format('Y-m-d H:i:s.u')]);

            return Outcome::done(['graduated_on' => $today->toDateString(),
                'previous' => ['graduated' => (bool) $before->graduated, 'actualdategrad' => $before->actualdategrad]]);
        });
    }
}
