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
        if ($data['mode'] === 'staged' && stripos($program->program ?? '', 'full') === false) {
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
            self::startStage($student, ['stage' => $data['stage'], 'started_on' => $data['started_on']]);
        }

        return $enrollment->fresh();
    }

    public static function startStage(Student $student, array $data): void
    {
        $enrollment = $student->currentEnrollment;
        if (! $enrollment || $enrollment->mode !== 'staged' || $enrollment->ended_on) {
            self::error('An open staged program enrollment is required.');
        }
        $last = $enrollment->stages->last();
        if ($last && ! $last->ended_on) {
            self::error('Complete the current stage before starting another; stages cannot overlap.');
        }
        if ($data['started_on'] < ($last?->ended_on ?? $enrollment->started_on)->format('Y-m-d')) {
            self::error('The stage cannot begin before the enrollment or previous stage ended.');
        }
        $days = DB::table('program_stage_definitions')->where('program_id', $enrollment->program_id)
            ->where('stage', $data['stage'])->value('service_days');
        if (! $days) {
            self::error('Configure positive service days for this program and stage before enrolling.');
        }
        $stage = $enrollment->stages()->create([
            'stage' => $data['stage'], 'started_on' => $data['started_on'], 'service_days' => $days,
        ]);
        $enrollment->forceFill(['status' => 'active'])->save();
        self::clearStatuses($student);
        $student->forceFill(['loa' => false, 'loa_date' => null, 'pif' => false, 'pifdate' => null])->save();
        self::event($enrollment, $stage, 'started', $data['started_on']);
    }

    public static function complete(Student $student, string $date): void
    {
        $enrollment = $student->currentEnrollment;
        if (! $enrollment || $enrollment->ended_on) {
            self::error('There is no open enrollment to complete.');
        }
        $stage = $enrollment->mode === 'staged' ? $enrollment->stages->last() : null;
        if ($stage?->ended_on) {
            self::error('This stage has already ended.');
        }
        if ($date < ($stage?->started_on ?? $enrollment->started_on)->format('Y-m-d')) {
            self::error('Completion cannot precede the start date.');
        }
        if ($stage) {
            $stage->forceFill(['completed_on' => $date, 'ended_on' => $date, 'status' => 'completed'])->save();
        }
        self::clearStatuses($student);
        $student->forceFill(['loa' => false, 'loa_date' => null])->save();
        if (! $stage || $stage->stage === 3) {
            $enrollment->forceFill(['ended_on' => $date, 'status' => 'graduated'])->save();
            $student->forceFill(['graduated' => true, 'actualdategrad' => $date])->save();
        } else {
            $enrollment->forceFill(['status' => 'between_stages'])->save();
        }
        self::event($enrollment, $stage, 'completed', $date);
    }

    public static function syncStatus(Student $student, array $before): void
    {
        $enrollment = $student->currentEnrollment;
        if (! $enrollment) {
            return;
        }
        if ((string) $student->program_id !== (string) $enrollment->program_id
            || $student->start_date?->format('Y-m-d') !== $enrollment->started_on?->format('Y-m-d')) {
            self::error('Use enrollment controls to change the current program or its start date.');
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
                'completed' => $stage && $stage->stage !== 3 ? 'between_stages' : 'graduated',
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
