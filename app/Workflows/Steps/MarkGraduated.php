<?php

namespace App\Workflows\Steps;

use App\Billing\EditLocks;
use App\Billing\Enrollments;
use App\Models\Student;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Workflows\Outcome;
use App\Workflows\Settings;
use App\Workflows\Step;
use App\Workflows\StepFailed;
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
        $graduation = Graduation::for($run);
        if (str_starts_with($graduation->form->enrollment_key, 'refresh:')) {
            throw new StepFailed('This enrollment history was discarded by a staging refresh. Start a new graduation form for the refreshed enrollment.');
        }
        $studentId = $graduation->student->id;

        return DB::transaction(function () use ($studentId, $graduation) {
            $lock = EditLocks::current($studentId);
            if ($lock !== null) {
                return Outcome::scheduled(now()->addMinutes(5),
                    "Waiting: {$lock->name} ({$lock->username}) has this learner's record open for editing. Retrying automatically.");
            }
            $student = Student::whereKey($studentId)->lockForUpdate()->firstOrFail();
            if (! $student->hasenrolled) {
                throw new StepFailed('The learner must be marked enrolled before graduation.');
            }
            if (! $student->start_date || now()->toDateString() <= $student->start_date->format('Y-m-d')) {
                throw new StepFailed('Graduation must be after the learner’s start date.');
            }
            $enrollment = $student->currentEnrollment;
            if ($enrollment && ((int) $graduation->form->enrollment_id !== (int) $enrollment->id
                || (int) $graduation->form->program_id !== (int) $enrollment->program_id)) {
                throw new StepFailed("This graduation form belongs to an earlier enrollment. Review the learner's current enrollment before continuing.");
            }
            if ($enrollment?->mode === 'staged' && config('billing.concurrent_stages')
                && $enrollment->stages->contains(fn ($stage) => ! $stage->ended_on)) {
                throw new StepFailed('Complete or close every active stage before graduating the learner.');
            }
            if ($enrollment?->mode === 'staged' && config('billing.concurrent_stages')
                && ($student->loa || $student->onextension || $student->wd || $student->sus || $student->inactive)) {
                throw new StepFailed('Clear the learner’s leave or other status before graduation.');
            }
            if ($enrollment?->mode === 'staged' && ! config('billing.concurrent_stages') && ! $enrollment->stages->last()?->isFinal()) {
                throw new StepFailed('Complete intermediate stages from enrollment history. Program graduation requires the final stage (stage 3 for legacy staged enrollments).');
            }
            $before = $student->getRawOriginal();
            $today = now()->startOfDay();
            DB::table('students')->where('id', $studentId)->update(['graduated' => 1, 'actualdategrad' => $today->format('Y-m-d H:i:s.u')]);
            Enrollments::syncStatus(Student::findOrFail($studentId), (array) $before);

            return Outcome::done(['graduated_on' => $today->toDateString(),
                'previous' => ['graduated' => (bool) $before['graduated'], 'actualdategrad' => $before['actualdategrad']]]);
        });
    }
}
