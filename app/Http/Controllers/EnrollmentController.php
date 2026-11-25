<?php

namespace App\Http\Controllers;

use App\Billing\Enrollments;
use App\Billing\RecordVersion;
use App\Models\Program;
use App\Models\Student;
use App\Models\StudentEnrollment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EnrollmentController extends Controller
{
    public function store(Request $request, Student $student)
    {
        $data = $request->validate([
            'version' => ['required', 'string'], 'action' => ['required', 'in:program,stage,complete,graduate,correct,reopen,resume,undo,cancel'],
            'started_on' => ['required_if:action,program,stage', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'effective_on' => ['required_if:action,complete,graduate,cancel,resume', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'program_id' => ['required_if:action,program', 'nullable', 'integer', 'exists:programs,id'],
            'mode' => ['nullable', 'in:whole,staged'],
            'stage' => ['required_if:mode,staged', 'nullable', 'integer', 'between:1,3'],
            'override_program_id' => ['nullable', 'integer', 'min:1', 'exists:programs,id'],
            'override_reason' => ['required_with:override_program_id', 'nullable', 'string', 'max:1000'],
            'accounts_settled' => [
                config('billing.concurrent_stages') && $student->currentEnrollment?->mode === 'staged'
                    ? 'exclude_unless:action,program,stage' : 'exclude_unless:action,program,stage,complete',
                'required', 'accepted',
            ],
            'enrollment_id' => ['required_if:action,correct,reopen,resume,undo,cancel', 'nullable', 'integer'],
            'stage_enrollment_id' => ['sometimes', 'nullable', 'integer'],
            'service_days' => ['required_if:action,correct', 'nullable', 'integer', 'between:1,10000'],
            'ended_on' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'reason' => ['required_if:action,correct,reopen,resume,undo,cancel', 'nullable', 'string', 'max:1000'],
            'confirm_undo' => ['exclude_unless:action,undo', 'required', 'accepted'],
        ]);
        DB::transaction(function () use ($student, $data) {
            $locked = Student::whereKey($student->id)->lockForUpdate()->firstOrFail();
            RecordVersion::check($locked, $data['version']);
            match ($data['action']) {
                'program' => Enrollments::start($locked, $data),
                'stage' => Enrollments::startStage($locked, $data),
                'complete' => Enrollments::complete($locked, $data['effective_on'], $data['stage_enrollment_id'] ?? null),
                'graduate' => Enrollments::graduate($locked, $data['effective_on']),
                'correct' => $this->correct($locked, $data),
                'reopen', 'resume', 'undo', 'cancel' => Enrollments::manage($locked, $data),
            };
        });

        return redirect()->route('students.show', $student)->with('status', 'Enrollment history saved.');
    }

    private function correct(Student $student, array $data): void
    {
        $enrollment = $student->enrollments()->findOrFail($data['enrollment_id']);
        if ($enrollment->mode === 'legacy') {
            Enrollments::error('Preserved legacy accounting context cannot be changed through the stage-duration editor.');
        }
        $stage = ! empty($data['stage_enrollment_id'])
            ? $enrollment->stages()->findOrFail($data['stage_enrollment_id']) : null;
        if ($enrollment->mode === 'staged' && ! $stage) {
            Enrollments::error('Select the stage whose agreed duration is being corrected.');
        }
        $unit = $stage ?? $enrollment;
        $before = $unit->getRawOriginal();
        $start = $data['started_on'] ?? $unit->started_on->format('Y-m-d');
        $end = $data['ended_on'] ?? $unit->ended_on?->format('Y-m-d');
        if ($end && $end < $start) {
            Enrollments::error('The corrected end cannot precede the start.');
        }
        if (! empty($data['ended_on']) && ! $unit->ended_on) {
            Enrollments::error('Use the completion or status action to end an active enrollment/stage.');
        }
        $concurrentStage = config('billing.concurrent_stages') && $stage;
        if ($concurrentStage && in_array($stage->status, ['wd', 'sus', 'inactive'], true)
            && $end !== $stage->ended_on?->format('Y-m-d')) {
            Enrollments::error('Correct learner-wide closure dates on the learner record, not on one stage.');
        }
        $siblings = $stage ? $enrollment->stages()->whereKeyNot($stage->id)
            : $student->enrollments()->whereKeyNot($enrollment->id);
        if ($concurrentStage) {
            $siblings->where('stage', $stage->stage);
        }
        if ($siblings->whereDate('started_on', '<', $end ?? '9999-12-31')
            ->where(fn ($query) => $query->whereNull('ended_on')->orWhereDate('ended_on', '>', $start))->exists()) {
            Enrollments::error('The corrected dates overlap another enrollment/stage.');
        }
        $unit->forceFill(['service_days' => $data['service_days'], 'started_on' => $start, 'ended_on' => $end]);
        if ($stage?->completed_on) {
            $stage->completed_on = $end;
        }
        $unit->save();
        $firstStage = $stage && $enrollment->stages()->first()->id === $stage->id;
        $previousProgramStart = $enrollment->started_on->format('Y-m-d');
        if ($concurrentStage) {
            $enrollment->started_on = $enrollment->stages()->min('started_on');
        } elseif ($firstStage) {
            $enrollment->started_on = $start;
        }
        $programStartChanged = $enrollment->started_on->format('Y-m-d') !== $previousProgramStart;
        if (! $concurrentStage && $stage && $enrollment->stages()->reorder('id', 'desc')->first()->id === $stage->id
            && $enrollment->ended_on
            && $enrollment->ended_on->format('Y-m-d') === substr($before['ended_on'] ?? '', 0, 10)) {
            $enrollment->ended_on = $end;
        }
        if ($stage && ($start < $enrollment->started_on->format('Y-m-d')
            || ($enrollment->ended_on && ($end === null || $end > $enrollment->ended_on->format('Y-m-d'))))) {
            Enrollments::error('Stage dates must remain within their program enrollment.');
        }
        if ($student->enrollments()->whereKeyNot($enrollment->id)
            ->whereDate('started_on', '<', $enrollment->ended_on?->format('Y-m-d') ?? '9999-12-31')
            ->where(fn ($query) => $query->whereNull('ended_on')->orWhereDate('ended_on', '>', $enrollment->started_on->format('Y-m-d')))
            ->exists()) {
            Enrollments::error('The corrected program dates overlap another program enrollment.');
        }
        $enrollment->save();
        if ($student->current_enrollment_id === $enrollment->id) {
            $student->start_date = $enrollment->started_on;
            if ($enrollment->status === 'graduated') {
                $student->actualdategrad = $enrollment->ended_on;
            } elseif (isset(Enrollments::STATUS_DATES[$enrollment->status]) && $enrollment->ended_on) {
                $student->setAttribute(Enrollments::STATUS_DATES[$enrollment->status], $enrollment->ended_on);
            }
            $student->save();
        }
        foreach ($enrollment->events as $event) {
            if ((string) $event->stage_enrollment_id === (string) $stage?->id) {
                if ($event->action === 'started') {
                    $event->effective_on = $start;
                } elseif ($end && in_array($event->action, ['completed', 'graduated', 'wd', 'sus', 'inactive'], true)
                    && $event->effective_on->format('Y-m-d') === substr($before['ended_on'] ?? '', 0, 10)) {
                    $event->effective_on = $end;
                }
                $event->save();
            } elseif (($concurrentStage ? $programStartChanged : $firstStage) && $event->stage_enrollment_id === null && $event->action === 'started') {
                $event->effective_on = $enrollment->started_on;
                $event->save();
            }
        }
        Enrollments::event($enrollment, $stage, 'corrected', today()->toDateString(),
            ['before' => $before, 'after' => $unit->getRawOriginal(), 'reason' => $data['reason']]);
    }

    public function settings()
    {
        return view('enrollments.settings', [
            'programs' => Program::where(fn ($query) => $query->whereRaw('LOWER(program) LIKE ?', ['%full%'])
                ->orWhereIn('id', StudentEnrollment::where('mode', 'staged')->where('program_stages', false)->select('program_id')))
                ->orderBy('id')->get(),
            'definitions' => DB::table('program_stage_definitions')->get()->groupBy('program_id'),
        ]);
    }

    public function saveSettings(Request $request)
    {
        $data = $request->validate([
            'days' => ['required', 'array'], 'days.*' => ['required', 'array:1,2,3'],
            'days.*.*' => ['nullable', 'integer', 'between:1,10000'],
        ]);
        DB::transaction(function () use ($data) {
            foreach ($data['days'] as $programId => $stages) {
                $program = Program::findOrFail($programId);
                if (stripos($program->program ?? '', 'full') === false
                    && ! StudentEnrollment::where('program_id', $program->id)->where('mode', 'staged')->where('program_stages', false)->exists()) {
                    Enrollments::error('Stage defaults apply only to Full Certificate programs.');
                }
                foreach ($stages as $stage => $days) {
                    if ($days !== null) {
                        DB::table('program_stage_definitions')->updateOrInsert(
                            ['program_id' => $program->id, 'stage' => $stage], ['service_days' => $days]);
                    } else {
                        DB::table('program_stage_definitions')->where('program_id', $program->id)->where('stage', $stage)->delete();
                    }
                }
            }
        });

        return back()->with('status', 'Stage defaults saved. Existing agreed service durations are unchanged.');
    }
}
