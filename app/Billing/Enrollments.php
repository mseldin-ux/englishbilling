<?php

namespace App\Billing;

use App\Models\EnrollmentEvent;
use App\Models\Program;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\StudentStageEnrollment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class Enrollments
{
    public const STAGES = [1 => 'Online coursework', 2 => 'In-person residency', 3 => 'Practicum internship'];

    public const STATUS_DATES = ['graduated' => 'actualdategrad', 'wd' => 'wd_date',
        'sus' => 'sus_date', 'inactive' => 'inactive_date', 'onextension' => 'extension_start_date'];

    public static function initialize(Student $student): void
    {
        if (! $student->program?->staged) {
            return;
        }
        if (! $student->start_date || $student->start_date->toDateString() > today()->toDateString()) {
            throw ValidationException::withMessages(['start_date' => 'A staged enrollment needs a start date no later than today.']);
        }
        $original = $student->getAttributes();
        self::start($student, ['program_id' => $student->program_id,
            'started_on' => $student->start_date->toDateString()]);
        $student->refresh();
        $before = $student->getRawOriginal();
        foreach (['id', 'program_id', 'start_date', 'current_enrollment_id', 'enrollment_revision'] as $field) {
            unset($original[$field]);
        }
        $student->forceFill($original)->save();
        self::syncStatus($student, $before);
    }

    public static function start(Student $student, array $data): StudentEnrollment
    {
        $previous = $student->currentEnrollment;
        if ($previous && ! $previous->ended_on) {
            self::error('Complete or close the current program enrollment before starting another.');
        }
        if ($previous && $data['started_on'] < $previous->ended_on->format('Y-m-d')) {
            self::error('Program enrollments cannot overlap.');
        }
        $program = Program::findOrFail($data['program_id']);
        $stageProgram = $program->staged ? $program : null;
        if ($stageProgram) {
            $data['mode'] = 'staged';
            $data['stage'] = ProgramStages::position($stageProgram);
            $program = Program::findOrFail($stageProgram->certificate_program_id);
        }
        $data['mode'] ??= 'whole';
        if (! $stageProgram && $data['mode'] === 'staged' && stripos($program->program ?? '', 'full') === false) {
            self::error('Only Full Certificate programs support staged enrollment.');
        }
        if (! $previous && ($student->transactions()->exists() || $student->invoices()->exists())) {
            $legacy = StudentEnrollment::create([
                'student_id' => $student->id, 'program_id' => $student->program_id, 'mode' => 'legacy',
                'started_on' => $student->start_date, 'ended_on' => $data['started_on'], 'status' => 'completed',
                'legacy_context' => ['student' => $student->getRawOriginal(),
                    'program' => $student->program?->getRawOriginal(),
                    'loas' => $student->loas->map(fn ($loa) => $loa->getRawOriginal())->all()],
            ]);
            if ($legacy->started_on && $legacy->started_on->format('Y-m-d') > $data['started_on']) {
                self::error('The new enrollment cannot precede the previous program start.');
            }
            foreach (['studenttransactions', 'invoices', 'studentpaymentplans', 'anticipatedpayment', 'loas'] as $table) {
                DB::table($table)->where('student_id', $student->id)->whereNull('enrollment_id')
                    ->update(['enrollment_id' => $legacy->id]);
            }
            self::event($legacy, null, 'completed', $data['started_on'], ['legacy_snapshot' => true]);
        }
        if ($data['mode'] === 'whole' && ($program->servicedays ?? 0) <= 0) {
            self::error('Set positive service days on the program before enrolling.');
        }
        $enrollment = StudentEnrollment::create([
            'student_id' => $student->id, 'program_id' => $program->id, 'mode' => $data['mode'],
            'program_stages' => $stageProgram !== null,
            'started_on' => $data['started_on'], 'service_days' => $data['mode'] === 'whole' ? $program->servicedays : null,
        ]);
        self::event($enrollment, null, 'started', $data['started_on']);
        $student->forceFill(['current_enrollment_id' => $enrollment->id, 'program_id' => $program->id,
            'start_date' => $data['started_on'], 'graddate' => null, 'pif' => false, 'pifdate' => null,
            'loa' => false, 'loa_date' => null, 'archived' => false]);
        self::clearStatuses($student);
        $student->save();
        $student->unsetRelation('currentEnrollment');
        if ($data['mode'] === 'staged') {
            self::startStage($student, ['stage' => $data['stage'], 'started_on' => $data['started_on'],
                'program_id' => $stageProgram?->id]);
        }
        $student->unsetRelation('program');

        return $enrollment->fresh();
    }

    public static function startStage(Student $student, array $data): void
    {
        $enrollment = $student->currentEnrollment;
        if (! $enrollment || $enrollment->mode !== 'staged' || $enrollment->ended_on) {
            self::error('An open staged program enrollment is required.');
        }
        $last = $enrollment->stages->last();
        $concurrent = config('billing.concurrent_stages');
        if ($concurrent && $student->onextension && ! $student->extension_start_date) {
            self::error('An extension start date is required before starting another stage.');
        }
        if (! $concurrent && $last && ! $last->ended_on) {
            self::error('Complete the current stage before starting another; stages cannot overlap.');
        }
        $earliest = $concurrent ? $enrollment->started_on : ($last?->ended_on ?? $enrollment->started_on);
        if ($data['started_on'] < $earliest->format('Y-m-d')) {
            self::error('The stage cannot begin before the enrollment or previous stage ended.');
        }
        if ($concurrent && $last) {
            $events = $enrollment->events()->whereNull('voided_at')->get();
            $lastExtensionClear = $events->where('action', 'status_cleared')
                ->last(fn ($event) => ($event->details['cleared_status'] ?? null) === 'onextension')?->id ?? 0;
            foreach ($events as $event) {
                $closing = in_array($event->action, ['wd', 'sus', 'inactive'], true)
                    || ($event->action === 'onextension' && (! $student->onextension || $event->id <= $lastExtensionClear));
                $cleared = in_array($event->action, ['resumed', 'status_cleared'], true)
                    && in_array($event->details['cleared_status'] ?? null, ['wd', 'sus', 'inactive', 'onextension'], true);
                if (($closing && $event->effective_on->format('Y-m-d') >= $data['started_on'])
                    || ($cleared && $event->effective_on->format('Y-m-d') > $data['started_on'])) {
                    self::error('The stage start overlaps earlier learner-wide status history. Review that history before enrolling.');
                }
            }
        }
        $extra = [];
        $details = [];
        if ($enrollment->program_stages) {
            $programId = $last ? $last->next_program_id : ($data['program_id'] ?? null);
            $override = ! empty($data['override_program_id']);
            if ($override) {
                if (! $last || trim($data['override_reason'] ?? '') === '') {
                    self::error('An out-of-sequence stage enrollment requires a reason.');
                }
                $details = ['stage_program_override' => ['expected_program_id' => $programId,
                    'selected_program_id' => (int) $data['override_program_id'], 'reason' => $data['override_reason']]];
                $programId = $data['override_program_id'];
            }
            if (! $programId || (! $override && ! empty($data['program_id']) && (int) $data['program_id'] !== (int) $programId)) {
                self::error('Select the recorded next stage program. A final stage has no successor.');
            }
            $program = Program::findOrFail($programId);
            ProgramStages::sequence($program);
            if ($program->certificate_program_id !== $enrollment->program_id) {
                self::error('The next stage must belong to this enrollment\'s shared certificate program.');
            }
            $data['stage'] = $last && ! $override ? $last->stage + 1 : ProgramStages::position($program);
            if ($data['stage'] > 3) {
                self::error('This enrollment already has three stages. Review its program sequence.');
            }
            $days = $program->servicedays;
            $extra = ['program_id' => $program->id, 'next_program_id' => $program->nextprog ?: null,
                'program_name' => $program->program];
        } else {
            if (empty($data['stage']) || ! isset(self::STAGES[$data['stage']])) {
                self::error('Choose a stage for this existing enrollment.');
            }
            $days = DB::table('program_stage_definitions')->where('program_id', $enrollment->program_id)
                ->where('stage', $data['stage'])->value('service_days');
        }
        if (! $days) {
            self::error('Configure positive service days for this program and stage before enrolling.');
        }
        if ($concurrent && $enrollment->stages()->where('stage', $data['stage'])
            ->where(fn ($query) => $query->whereNull('ended_on')->orWhereDate('ended_on', '>', $data['started_on']))->exists()) {
            self::error('The same stage cannot be enrolled more than once at the same time.');
        }
        if ($concurrent && $last) {
            $details['inherited_leave_ids'] = $student->loas()->where('enrollment_id', $enrollment->id)
                ->whereNotNull('stage_enrollment_id')
                ->where(fn ($query) => $query->whereNull('loa_end')->orWhereDate('loa_end', '>', $data['started_on']))
                ->pluck('id')->map(fn ($id) => (int) $id)->all();
        }
        $stage = $enrollment->stages()->create([
            'stage' => $data['stage'], 'started_on' => $data['started_on'], 'service_days' => $days,
            ...$extra,
        ]);
        $enrollment->forceFill(['status' => 'active'])->save();
        if (! $concurrent || ! $last) {
            self::clearStatuses($student);
            $student->forceFill(['loa' => false, 'loa_date' => null, 'pif' => false, 'pifdate' => null]);
        }
        if ($enrollment->program_stages) {
            $student->program_id = $program->id;
        }
        $student->save();
        $student->unsetRelation('program');
        self::event($enrollment, $stage, 'started', $data['started_on'], $details);
        if ($concurrent && $student->onextension) {
            $stage->forceFill(['status' => 'onextension'])->save();
            self::event($enrollment, $stage, 'onextension',
                max($data['started_on'], $student->extension_start_date->format('Y-m-d')));
        }
    }

    public static function graduate(Student $student, string $date): void
    {
        self::validateGraduation($student, $date);
        $enrollment = $student->currentEnrollment;
        if (! config('billing.concurrent_stages') || ! $enrollment || $enrollment->mode !== 'staged' || $enrollment->ended_on) {
            self::error('An open staged enrollment is required for graduation.');
        }
        if ($student->loa || $student->onextension || $student->wd || $student->sus || $student->inactive) {
            self::error('Clear the learner’s leave or other status before graduation.');
        }
        $before = $student->getRawOriginal();
        $student->forceFill(['graduated' => true, 'actualdategrad' => $date])->save();
        self::syncStatus($student, $before);
    }

    public static function complete(Student $student, string $date, ?int $stageId = null): void
    {
        $enrollment = $student->currentEnrollment;
        if (! $enrollment || $enrollment->ended_on) {
            self::error('There is no open enrollment to complete.');
        }
        $concurrent = config('billing.concurrent_stages') && $enrollment->mode === 'staged';
        if ($concurrent) {
            $active = $enrollment->stages->whereNull('ended_on');
            if ($stageId === null && $active->count() !== 1) {
                self::error('Select the stage to complete.');
            }
            $stage = $stageId === null ? $active->first() : $enrollment->stages->firstWhere('id', $stageId);
            if (! $stage) {
                self::error('The selected stage does not belong to this enrollment.');
            }
        } else {
            $stage = $enrollment->mode === 'staged' ? $enrollment->stages->last() : null;
        }
        if (! $concurrent && (! $stage || $stage->isFinal())) {
            self::validateGraduation($student, $date);
        }
        if ($stage?->ended_on) {
            self::error('This stage has already ended.');
        }
        if ($date < ($stage?->started_on ?? $enrollment->started_on)->format('Y-m-d')) {
            self::error('Completion cannot precede the start date.');
        }
        if ($concurrent && $enrollment->events()->whereNull('voided_at')->where('stage_enrollment_id', $stage->id)
            ->whereIn('action', ['resumed', 'onextension', 'wd', 'sus', 'inactive', 'cancelled'])
            ->whereDate('effective_on', '>', $date)->exists()) {
            self::error('Completion cannot precede later stage status activity. Correct the history first.');
        }
        if ($stage) {
            $stage->forceFill(['completed_on' => $date, 'ended_on' => $date, 'status' => 'completed'])->save();
        }
        if ($concurrent) {
            $remaining = $enrollment->stages()->whereNull('ended_on')->exists();
            $enrollment->forceFill(['status' => $remaining ? 'active' : 'between_stages'])->save();
            self::event($enrollment, $stage, 'completed', $date, ['independent_stage' => true]);

            return;
        }
        self::clearStatuses($student);
        $student->forceFill(['loa' => false, 'loa_date' => null])->save();
        if (! $stage || $stage->isFinal()) {
            $enrollment->forceFill(['ended_on' => $date, 'status' => 'graduated'])->save();
            $student->forceFill(['graduated' => true, 'actualdategrad' => $date])->save();
        } else {
            $enrollment->forceFill(['status' => 'between_stages'])->save();
        }
        self::event($enrollment, $stage, 'completed', $date);
    }

    public static function validateGraduation(Student $student, string $date): void
    {
        if (! $student->hasenrolled) {
            self::error('A learner must be enrolled before being marked graduated.');
        }
        if (! $student->start_date || $date <= $student->start_date->format('Y-m-d')) {
            self::error('Graduation must be after the learner’s start date.');
        }
    }

    public static function syncStatus(Student $student, array $before): void
    {
        $enrollment = $student->currentEnrollment;
        if (! $enrollment) {
            return;
        }
        $currentProgram = $enrollment->program_stages ? $enrollment->stages->last()?->program_id : $enrollment->program_id;
        if ((string) $student->program_id !== (string) $currentProgram
            || $student->start_date?->format('Y-m-d') !== $enrollment->started_on?->format('Y-m-d')) {
            self::error('Use enrollment controls to change the current program or its start date.');
        }
        if (config('billing.concurrent_stages') && $enrollment->mode === 'staged') {
            self::syncConcurrentStatus($student, $before, $enrollment);

            return;
        }
        foreach (self::STATUS_DATES as $flag => $field) {
            $enrollment->unsetRelation('stages');
            $date = $student->getRawOriginal($field);
            $changed = (bool) ($before[$flag] ?? false) !== (bool) $student->$flag
                || ($before[$field] ?? null) !== $date;
            if (! $changed) {
                continue;
            }
            if (! $student->$flag) {
                if (! ($before[$flag] ?? false)) {
                    continue;
                }
                $stillClosed = collect(self::STATUS_DATES)->except('onextension')->keys()
                    ->contains(fn ($other) => (bool) $student->$other);
                if ($stillClosed || ! $enrollment->ended_on) {
                    if (! $stillClosed && $flag === 'onextension') {
                        $enrollment->stages()->where('status', 'onextension')->whereNull('ended_on')->update(['status' => 'active']);
                    }
                    self::event($enrollment, null, 'status_cleared', today()->toDateString(), ['cleared_status' => $flag]);

                    continue;
                }
                $stage = $enrollment->mode === 'staged' ? $enrollment->stages->last() : null;
                self::event($enrollment, $stage, 'resumed', today()->toDateString(), ['cleared_status' => $flag]);
                $enrollment->forceFill(['status' => 'active', 'ended_on' => null])->save();
                $stage?->forceFill(['status' => 'active', 'ended_on' => null, 'completed_on' => null])->save();

                continue;
            }
            if (! $date) {
                self::error("A date is required for {$flag} on a managed enrollment.");
            }
            $date = substr($date, 0, 10);
            if ($date > today()->toDateString()) {
                self::error('Settlement status dates cannot be in the future.');
            }
            $stage = $enrollment->mode === 'staged' ? $enrollment->stages->last() : null;
            if ($date < ($stage?->started_on ?? $enrollment->started_on)->format('Y-m-d')) {
                self::error('A status date cannot precede the enrollment/stage start.');
            }
            if ($before[$flag] ?? false) {
                $event = $enrollment->events()->whereNull('voided_at')->whereIn('action', $flag === 'graduated' ? ['graduated', 'completed'] : [$flag])
                    ->where('stage_enrollment_id', $stage?->id)->reorder('id', 'desc')->first();
                if (! $event) {
                    self::error('No matching enrollment status event exists to correct.');
                }
                $oldDate = $event->effective_on->format('Y-m-d');
                $event->effective_on = $date;
                $event->save();
                if ($flag !== 'onextension') {
                    $enrollment->forceFill(['ended_on' => $date])->save();
                    $stage?->forceFill(['ended_on' => $date,
                        'completed_on' => $flag === 'graduated' ? $date : null])->save();
                }
                self::event($enrollment, $stage, 'corrected', today()->toDateString(),
                    ['event_id' => $event->id, 'previous_date' => $oldDate, 'effective_on' => $date]);

                continue;
            }
            if ($enrollment->ended_on || $stage?->ended_on) {
                self::error('This enrollment/stage has already ended; use its history correction controls.');
            }
            if ($flag === 'graduated' && $enrollment->program_stages && $stage && ! $stage->isFinal()) {
                self::error('Complete this intermediate stage from enrollment history; only the final stage can graduate.');
            }
            self::event($enrollment, $stage, $flag, $date);
            $status = $flag === 'graduated' ? 'graduated' : $flag;
            if ($stage) {
                $stage->forceFill(['status' => $status,
                    'ended_on' => $flag === 'onextension' ? null : $date,
                    'completed_on' => $flag === 'graduated' ? $date : null])->save();
            }
            if ($flag !== 'onextension') {
                $enrollment->forceFill(['status' => $status, 'ended_on' => $date])->save();
            }
        }
    }

    private static function syncConcurrentStatus(Student $student, array $before, StudentEnrollment $enrollment): void
    {
        foreach (self::STATUS_DATES as $flag => $field) {
            $date = $student->getRawOriginal($field);
            if ((bool) ($before[$flag] ?? false) === (bool) $student->$flag && ($before[$field] ?? null) === $date) {
                continue;
            }
            if (! $student->$flag) {
                if (! ($before[$flag] ?? false)) {
                    continue;
                }
                $otherClosed = collect(self::STATUS_DATES)->except(['onextension', $flag])->keys()
                    ->contains(fn ($other) => (bool) $student->$other);
                if ($otherClosed) {
                    if ($flag === 'onextension') {
                        self::event($enrollment, null, 'status_cleared', today()->toDateString(),
                            ['cleared_status' => $flag, 'independent_stage' => true]);

                        continue;
                    }
                    self::error('Clear other closing statuses before resuming the learner.');
                }
                foreach ($enrollment->stages()->where('status', $flag)->get() as $stage) {
                    if ($stage->ended_on && $stage->ended_on->format('Y-m-d') > today()->toDateString()) {
                        self::error('The return date cannot precede the stage closure.');
                    }
                    $restored = $flag !== 'onextension' && $student->onextension ? 'onextension' : 'active';
                    $stage->forceFill(['status' => $restored, 'ended_on' => null])->save();
                    self::event($enrollment, $stage, 'resumed', today()->toDateString(), ['cleared_status' => $flag]);
                }
                $open = $enrollment->stages()->whereNull('ended_on')->exists();
                $enrollment->forceFill(['status' => $open ? 'active' : 'between_stages', 'ended_on' => null])->save();
                self::event($enrollment, null, 'status_cleared', today()->toDateString(),
                    ['cleared_status' => $flag, 'independent_stage' => true]);

                continue;
            }
            if (! $date) {
                self::error("A date is required for {$flag} on a managed enrollment.");
            }
            $date = substr($date, 0, 10);
            if ($date > today()->toDateString()) {
                self::error('Settlement status dates cannot be in the future.');
            }
            if ($flag === 'graduated') {
                if ($student->loa || $student->onextension || $student->wd || $student->sus || $student->inactive) {
                    self::error('Clear the learner’s leave or other status before graduation.');
                }
                if ($enrollment->stages()->whereNull('ended_on')->exists()) {
                    self::error('Complete or close all active stages before graduating the learner.');
                }
                if ($enrollment->stages()->whereDate('ended_on', '>', $date)->exists()) {
                    self::error('Graduation cannot precede a stage end date.');
                }
                $stages = collect();
            } else {
                if ($flag !== 'onextension' && $enrollment->stages()->where('status', '!=', $flag)
                    ->whereNotNull('ended_on')->whereDate('ended_on', '>', $date)->exists()) {
                    self::error('This status date precedes a later stage closure. Review the stage history first.');
                }
                if ($flag === 'onextension' && ($before[$flag] ?? false)) {
                    $lastCleared = $enrollment->events()->where('action', 'status_cleared')->get()
                        ->last(fn ($event) => ($event->details['cleared_status'] ?? null) === 'onextension')?->id ?? 0;
                    $stageIds = $enrollment->events()->whereNull('voided_at')->where('action', 'onextension')
                        ->where('id', '>', $lastCleared)->whereNotNull('stage_enrollment_id')->pluck('stage_enrollment_id');
                    $stages = $enrollment->stages()->whereIn('id', $stageIds)->get();
                } else {
                    $stages = ($before[$flag] ?? false)
                        ? $enrollment->stages()->where('status', $flag)->get()
                        : $enrollment->stages()->whereNull('ended_on')->get();
                }
                if ($stages->isEmpty()) {
                    self::error('There are no active stages for this status change.');
                }
            }
            foreach ($stages as $stage) {
                $stageStart = $stage->started_on->format('Y-m-d');
                if ($date < $stageStart && $flag !== 'onextension') {
                    self::error('A status date cannot precede an active stage start.');
                }
                $stageDate = $flag === 'onextension' ? max($date, $stageStart) : $date;
                if ($before[$flag] ?? false) {
                    $event = $enrollment->events()->whereNull('voided_at')->where('action', $flag)
                        ->where('stage_enrollment_id', $stage->id)->reorder('id', 'desc')->first();
                    if (! $event) {
                        self::error('No matching stage status event exists to correct.');
                    }
                    $oldDate = $event->effective_on->format('Y-m-d');
                    $event->forceFill(['effective_on' => $stageDate])->save();
                    self::event($enrollment, $stage, 'corrected', today()->toDateString(),
                        ['event_id' => $event->id, 'previous_date' => $oldDate, 'effective_on' => $stageDate]);
                } else {
                    self::event($enrollment, $stage, $flag, $stageDate);
                }
                if ($flag !== 'onextension' || ! ($before[$flag] ?? false)) {
                    $stage->forceFill(['status' => $flag, 'ended_on' => $flag === 'onextension' ? null : $date])->save();
                }
            }
            if ($flag === 'graduated') {
                if ($before[$flag] ?? false) {
                    $event = $enrollment->events()->whereNull('voided_at')->where('action', 'graduated')
                        ->whereNull('stage_enrollment_id')->reorder('id', 'desc')->first();
                    if (! $event) {
                        self::error('No matching graduation event exists to correct.');
                    }
                    $oldDate = $event->effective_on->format('Y-m-d');
                    $event->forceFill(['effective_on' => $date])->save();
                    self::event($enrollment, null, 'corrected', today()->toDateString(),
                        ['event_id' => $event->id, 'previous_date' => $oldDate, 'effective_on' => $date]);
                } else {
                    self::event($enrollment, null, 'graduated', $date, ['learner_only' => true]);
                }
            }
            if ($flag !== 'onextension') {
                $enrollment->forceFill(['status' => $flag, 'ended_on' => $date])->save();
            }
        }
    }

    public static function manage(Student $student, array $data): void
    {
        $enrollment = $student->enrollments()->findOrFail($data['enrollment_id']);
        if ($enrollment->mode === 'legacy') {
            self::error('Preserved legacy context cannot be reopened or cancelled through enrollment controls.');
        }
        $stage = ! empty($data['stage_enrollment_id'])
            ? $enrollment->stages()->findOrFail($data['stage_enrollment_id']) : null;
        if ($enrollment->mode === 'staged' && ! $stage) {
            self::error('Select the stage to reopen or cancel.');
        }
        if (config('billing.concurrent_stages') && $stage) {
            self::manageConcurrentStage($student, $enrollment, $stage, $data);

            return;
        }
        $unit = $stage ?? $enrollment;
        $before = $unit->getRawOriginal();
        $latest = $student->current_enrollment_id === $enrollment->id
            && (! $stage || $enrollment->stages->last()->id === $stage->id);
        if ($data['action'] !== 'undo') {
            $date = $data['effective_on'] ?? today()->toDateString();
            $last = $enrollment->events()->whereNull('voided_at')->where('stage_enrollment_id', $stage?->id)
                ->whereIn('action', ['started', 'resumed', 'completed', 'graduated', 'wd', 'sus', 'inactive', 'cancelled'])
                ->reorder('effective_on', 'desc')->orderByDesc('id')->first();
            if ($last && $date < $last->effective_on->format('Y-m-d')) {
                self::error('This action cannot precede recorded academic activity. Correct or undo the mistaken closure first.');
            }
        }
        if (in_array($data['action'], ['reopen', 'resume', 'undo'], true)) {
            if (! $latest || ! $unit->ended_on) {
                self::error('Only the latest ended enrollment/stage can be reopened; later enrollments or stages cannot overlap.');
            }
            if ($data['action'] === 'undo') {
                $event = $enrollment->events()->whereNull('voided_at')->where('stage_enrollment_id', $stage?->id)
                    ->whereIn('action', ['completed', 'graduated', 'wd', 'sus', 'inactive', 'cancelled'])
                    ->reorder('id', 'desc')->first();
                if (! $event) {
                    self::error('No closure event exists to undo. Correct this history explicitly instead.');
                }
                $event->voided_at = now();
                $event->save();
                self::event($enrollment, $stage, 'closure_undone', today()->toDateString(),
                    ['event_id' => $event->id, 'action' => $event->action, 'effective_on' => $event->effective_on->format('Y-m-d'),
                        'reason' => $data['reason']]);
                $previous = $enrollment->events()->whereNull('voided_at')->where('stage_enrollment_id', $stage?->id)
                    ->whereIn('action', ['started', 'resumed', 'onextension', 'completed', 'graduated', 'wd', 'sus', 'inactive', 'cancelled'])
                    ->reorder('id', 'desc')->first();
                $status = match ($previous?->action) {
                    'started', 'resumed', null => 'active',
                    default => $previous->action,
                };
                $end = in_array($status, ['active', 'onextension'], true) ? null : $previous->effective_on;
            } else {
                $date = $data['effective_on'] ?? today()->toDateString();
                if ($date < $unit->ended_on->format('Y-m-d') || $date > today()->toDateString()) {
                    self::error('The return date must be on or after closure and no later than today.');
                }
                $status = 'active';
                $end = null;
                self::event($enrollment, $stage, 'resumed', $date,
                    ['before' => $before, 'reason' => $data['reason']]);
            }
            $unit->forceFill(['status' => $status, 'ended_on' => $end]);
            if ($stage) {
                $stage->completed_on = in_array($status, ['completed', 'graduated'], true) ? $end : null;
            }
            $unit->save();
            $parentStatus = match ($status) {
                'completed' => $stage && ! $stage->isFinal() ? 'between_stages' : 'graduated',
                'onextension' => 'active',
                default => $status,
            };
            $enrollment->forceFill(['status' => $parentStatus, 'ended_on' => $parentStatus === 'between_stages' ? null : $end])->save();
            self::clearStatuses($student);
            if (isset(self::STATUS_DATES[$parentStatus])) {
                $student->setAttribute($parentStatus, true);
                $student->setAttribute(self::STATUS_DATES[$parentStatus], $end);
            }
            if ($status === 'onextension') {
                $student->forceFill(['onextension' => true, 'extension_start_date' => $previous->effective_on]);
            }
            $student->save();

            return;
        } else {
            if ($unit->status === 'cancelled') {
                self::error('This enrollment/stage is already cancelled.');
            }
            if ($data['effective_on'] < $unit->started_on->format('Y-m-d')) {
                self::error('Cancellation cannot precede the start date.');
            }
            $unit->forceFill(['status' => 'cancelled', 'ended_on' => $unit->ended_on ?? $data['effective_on']])->save();
            if ($latest) {
                $enrollment->forceFill(['status' => 'cancelled', 'ended_on' => $unit->ended_on])->save();
                self::clearStatuses($student);
                $student->save();
            }
        }
        self::event($enrollment, $stage, 'cancelled', $data['effective_on'],
            ['before' => $before, 'after' => $unit->getRawOriginal(), 'reason' => $data['reason']]);
    }

    private static function manageConcurrentStage(Student $student, StudentEnrollment $enrollment, StudentStageEnrollment $stage, array $data): void
    {
        if ($student->current_enrollment_id !== $enrollment->id || $enrollment->ended_on) {
            self::error('Only a stage in the open current enrollment can be changed this way.');
        }
        if ($student->wd || $student->sus || $student->inactive || $student->graduated || $student->onextension) {
            self::error('Clear the learner-wide status before changing an individual stage.');
        }
        $before = $stage->getRawOriginal();
        $action = $data['action'];
        $last = $enrollment->events()->whereNull('voided_at')->where('stage_enrollment_id', $stage->id)
            ->whereIn('action', ['started', 'resumed', 'completed', 'wd', 'sus', 'inactive', 'cancelled'])
            ->reorder('effective_on', 'desc')->orderByDesc('id')->first();
        $date = $data['effective_on'] ?? today()->toDateString();
        if ($action !== 'undo' && $last && $date < $last->effective_on->format('Y-m-d')) {
            self::error('This action cannot precede recorded academic activity. Correct or undo the mistaken closure first.');
        }
        if (in_array($action, ['reopen', 'resume', 'undo'], true)) {
            if (! $stage->ended_on) {
                self::error('Only an ended stage can be reopened.');
            }
            $reopenFrom = $action === 'undo' ? $stage->started_on->format('Y-m-d') : $date;
            if ($enrollment->stages()->whereKeyNot($stage->id)->where('stage', $stage->stage)
                ->where(fn ($query) => $query->whereNull('ended_on')->orWhereDate('ended_on', '>', $reopenFrom))->exists()) {
                self::error('Another enrollment in this stage would overlap.');
            }
            if ($action === 'undo') {
                $event = $enrollment->events()->whereNull('voided_at')->where('stage_enrollment_id', $stage->id)
                    ->whereIn('action', ['completed', 'wd', 'sus', 'inactive', 'cancelled'])->reorder('id', 'desc')->first();
                if (! $event) {
                    self::error('No closure event exists to undo.');
                }
                $event->forceFill(['voided_at' => now()])->save();
                $previous = $enrollment->events()->whereNull('voided_at')->where('stage_enrollment_id', $stage->id)
                    ->whereIn('action', ['started', 'resumed', 'onextension', 'completed', 'wd', 'sus', 'inactive', 'cancelled'])
                    ->reorder('id', 'desc')->first();
                $status = in_array($previous?->action, ['started', 'resumed', null], true) ? 'active' : $previous->action;
                $end = in_array($status, ['active', 'onextension'], true) ? null : $previous->effective_on;
                self::event($enrollment, $stage, 'closure_undone', today()->toDateString(),
                    ['event_id' => $event->id, 'action' => $event->action, 'effective_on' => $event->effective_on->format('Y-m-d'),
                        'reason' => $data['reason']]);
            } else {
                if ($date < $stage->ended_on->format('Y-m-d') || $date > today()->toDateString()) {
                    self::error('The return date must be on or after closure and no later than today.');
                }
                $status = 'active';
                $end = null;
                self::event($enrollment, $stage, 'resumed', $date, ['before' => $before, 'reason' => $data['reason']]);
            }
            $stage->forceFill(['status' => $status, 'ended_on' => $end,
                'completed_on' => $status === 'completed' ? $end : null])->save();
        } else {
            if ($stage->status === 'cancelled') {
                self::error('This stage is already cancelled.');
            }
            if ($date < $stage->started_on->format('Y-m-d')) {
                self::error('Cancellation cannot precede the stage start date.');
            }
            $stage->forceFill(['status' => 'cancelled', 'ended_on' => $stage->ended_on ?? $date])->save();
            self::event($enrollment, $stage, 'cancelled', $date,
                ['before' => $before, 'after' => $stage->getRawOriginal(), 'reason' => $data['reason'], 'independent_stage' => true]);
        }
        $open = $enrollment->stages()->whereNull('ended_on')->exists();
        $enrollment->forceFill(['status' => $open ? 'active' : 'between_stages'])->save();
    }

    private static function clearStatuses(Student $student): void
    {
        foreach (self::STATUS_DATES as $flag => $field) {
            $student->setAttribute($flag, false);
            $student->setAttribute($field, null);
        }
    }

    public static function event(StudentEnrollment $enrollment, ?StudentStageEnrollment $stage, string $action, string $date, array $details = []): void
    {
        EnrollmentEvent::create(['enrollment_id' => $enrollment->id, 'stage_enrollment_id' => $stage?->id,
            'action' => $action, 'effective_on' => $date, 'details' => $details, 'user_id' => auth()->id()]);
        DB::table('students')->where('id', $enrollment->student_id)->increment('enrollment_revision');
    }

    public static function error(string $message): never
    {
        throw ValidationException::withMessages(['enrollment' => $message]);
    }
}
