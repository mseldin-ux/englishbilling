<?php

namespace App\Billing;

use App\Models\LeaveOfAbsence;
use App\Models\Program;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\StudentStageEnrollment;
use Brick\Math\BigDecimal;
use Brick\Math\BigRational;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class EnrollmentAccounting
{
    public static function ratio(Student $student, ?StudentEnrollment $enrollment, ?StudentStageEnrollment $stage, string $date): BigRational
    {
        if (! $enrollment) {
            return ProgramProgress::calculate($student, $date)['ratio'];
        }
        $asOf = CarbonImmutable::parse($date, 'UTC')->startOfDay();
        if ($enrollment->mode === 'legacy') {
            if ($enrollment->ended_on && $enrollment->ended_on->format('Y-m-d') <= $date) {
                return BigRational::of(1);
            }

            return ProgramProgress::calculate(self::legacyStudent($student, $enrollment), $date)['ratio'];
        }
        if ($enrollment->mode === 'staged' && ! $stage) {
            Enrollments::error("Enrollment {$enrollment->id} has a transaction without a stage.");
        }
        $unit = $stage ?? $enrollment;
        if ($asOf->toDateString() < $unit->started_on->format('Y-m-d')) {
            return BigRational::of(0);
        }
        foreach ($enrollment->events as $event) {
            if (! $event->voided_at && ! ($event->details['learner_only'] ?? false)
                && ($event->stage_enrollment_id === null || (string) $event->stage_enrollment_id === (string) $stage?->id)
                && in_array($event->action, ['completed', 'graduated', 'wd', 'sus', 'inactive', 'onextension'], true)
                && $event->effective_on->format('Y-m-d') <= $asOf->toDateString()) {
                return BigRational::of(1);
            }
        }
        $days = $unit->service_days;
        if (! $days || $days <= 0) {
            Enrollments::error("Enrollment {$enrollment->id} has no positive agreed service duration.");
        }
        $start = CarbonImmutable::parse($unit->started_on->format('Y-m-d'), 'UTC');
        $intervals = [];
        $closed = null;
        foreach ($enrollment->events->whereNull('voided_at')->sortBy([
            ['effective_on', 'asc'], ['id', 'asc'],
        ]) as $event) {
            if ((string) $event->stage_enrollment_id !== (string) $stage?->id || $event->effective_on->format('Y-m-d') > $date) {
                continue;
            }
            if (in_array($event->action, ['cancelled', 'completed', 'graduated', 'wd', 'sus', 'inactive'], true)) {
                $closed ??= max($start->getTimestamp(), CarbonImmutable::parse($event->effective_on->format('Y-m-d'), 'UTC')->getTimestamp());
            } elseif ($event->action === 'resumed' && $closed !== null) {
                $intervals[] = [$closed, CarbonImmutable::parse($event->effective_on->format('Y-m-d'), 'UTC')->getTimestamp()];
                $closed = null;
            }
        }
        if ($closed !== null) {
            $intervals[] = [$closed, $asOf->getTimestamp()];
        }
        foreach ($student->loas as $loa) {
            if (! $loa->loa_start) {
                continue;
            }
            $leaveStart = CarbonImmutable::parse($loa->getRawOriginal('loa_start'), 'UTC');
            if ($leaveStart >= $asOf) {
                continue;
            }
            $leaveEnd = $loa->loa_end ? CarbonImmutable::parse($loa->getRawOriginal('loa_end'), 'UTC') : $asOf;
            if ($leaveEnd < $leaveStart) {
                LeavePeriods::invalidDates($student, $loa);
            }
            $begin = max($start->getTimestamp(), $leaveStart->getTimestamp());
            $end = min($asOf->getTimestamp(), $leaveEnd->getTimestamp());
            if ($end > $begin) {
                $intervals[] = [$begin, $end];
            }
        }
        sort($intervals);
        $excluded = 0;
        $lastEnd = $start->getTimestamp();
        foreach ($intervals as [$begin, $end]) {
            $excluded += max(0, $end - max($lastEnd, $begin));
            $lastEnd = max($lastEnd, $end);
        }
        $served = max(0, $asOf->getTimestamp() - $start->getTimestamp() - $excluded);

        return BigRational::ofFraction(min($served, $days * 86400), $days * 86400);
    }

    public static function statusAt(StudentEnrollment $enrollment, string $date): string
    {
        $status = 'active';
        $activeStages = [];
        foreach ($enrollment->events->whereNull('voided_at')->sortBy([
            ['effective_on', 'asc'], ['id', 'asc'],
        ]) as $event) {
            if ($event->effective_on->format('Y-m-d') > $date) {
                continue;
            }
            if ($event->stage_enrollment_id !== null) {
                if (in_array($event->action, ['started', 'resumed'], true)) {
                    $activeStages[$event->stage_enrollment_id] = true;
                } elseif (in_array($event->action, ['completed', 'graduated', 'wd', 'sus', 'inactive', 'cancelled'], true)) {
                    unset($activeStages[$event->stage_enrollment_id]);
                }
            }
            if ($event->action === 'completed') {
                $stage = $enrollment->stages->firstWhere('id', $event->stage_enrollment_id);
                $status = ($event->details['independent_stage'] ?? false)
                    ? ($activeStages ? 'active' : 'between_stages')
                    : (! $stage || $stage->isFinal() ? 'graduated' : 'between_stages');
            } elseif ($event->action === 'cancelled' && ($event->details['independent_stage'] ?? false)) {
                $status = $activeStages ? 'active' : 'between_stages';
            } elseif ($event->action === 'status_cleared' && ($event->details['independent_stage'] ?? false)
                && ($event->details['cleared_status'] ?? null) !== 'onextension') {
                $status = $activeStages ? 'active' : 'between_stages';
            } elseif (in_array($event->action, ['graduated', 'wd', 'sus', 'inactive', 'cancelled'], true)) {
                $status = $event->action;
            } elseif (in_array($event->action, ['started', 'resumed'], true)) {
                $status = 'active';
            }
        }

        return $status;
    }

    public static function legacyStudent(Student $student, StudentEnrollment $enrollment): Student
    {
        $context = $enrollment->legacy_context;
        if (! $context) {
            Enrollments::error("Legacy enrollment {$enrollment->id} has no preserved accounting context.");
        }
        $snapshot = new Student;
        $snapshot->setRawAttributes($context['student'], true);
        $snapshot->setRelation('reportStatusDates', collect([
            'current' => LegacyReportDates::forSnapshot($student, $enrollment->id),
        ]));
        $program = null;
        if ($context['program']) {
            $program = new Program;
            $program->setRawAttributes($context['program'], true);
        }
        $snapshot->setRelation('program', $program);
        $snapshot->setRelation('loas', collect($context['loas'])->map(function ($attributes) {
            $loa = new LeaveOfAbsence;
            $loa->setRawAttributes($attributes, true);

            return $loa;
        }));

        return $snapshot;
    }

    public static function earned(Student $student, iterable $transactions, string $asOf, Closure $amount): BigRational
    {
        $enrollments = $student->current_enrollment_id
            ? $student->enrollments->keyBy('id') : collect();
        $groups = [];
        foreach ($transactions as $transaction) {
            if ($student->current_enrollment_id && ! $transaction->enrollment_id) {
                Enrollments::error("Learner {$student->id} has a transaction without program enrollment attribution.");
            }
            $key = ($transaction->enrollment_id ?? '').':'.($transaction->stage_enrollment_id ?? '').':'.($transaction->recognition ?? 'service');
            $groups[$key]['enrollment_id'] = $transaction->enrollment_id;
            $groups[$key]['stage_enrollment_id'] = $transaction->stage_enrollment_id;
            $groups[$key]['recognition'] = $transaction->recognition;
            $type = strtolower($transaction->transactiontype ?? 'None');
            $groups[$key]['types'][$type] = Money::add($groups[$key]['types'][$type] ?? '0.00',
                (string) BigDecimal::of((string) ($transaction->transactionamount ?? '0'))->abs());
        }
        $earned = BigRational::of(0);
        foreach ($groups as $group) {
            $enrollment = $group['enrollment_id'] ? $enrollments->get($group['enrollment_id']) : null;
            if ($group['enrollment_id'] && ! $enrollment) {
                Enrollments::error('A transaction references an enrollment belonging to another learner.');
            }
            $stage = $group['stage_enrollment_id'] ? $enrollment?->stages->firstWhere('id', $group['stage_enrollment_id']) : null;
            if ($group['stage_enrollment_id'] && ! $stage) {
                Enrollments::error('A transaction references a stage outside its enrollment.');
            }
            $ratio = $enrollment && $enrollment->mode !== 'legacy' && $group['recognition'] === 'application'
                ? BigRational::of(1) : self::ratio($student, $enrollment, $stage, $asOf);
            $earned = $earned->plus(BigRational::of($amount($group['types']))->multipliedBy($ratio));
        }

        return $earned;
    }

    public static function expandAllocations(iterable $transactions): array
    {
        $transactions = collect($transactions)->all();
        if (! config('billing.concurrent_stages') || ! $transactions) {
            return $transactions;
        }
        $allocations = [];
        foreach (array_chunk(array_map(fn ($transaction) => $transaction->id, $transactions), 300) as $ids) {
            $rows = DB::table('transaction_stage_allocations as allocation')
                ->join('student_stage_enrollments as stage', 'stage.id', '=', 'allocation.stage_enrollment_id')
                ->join('student_enrollments as enrollment', 'enrollment.id', '=', 'stage.enrollment_id')
                ->whereIn('allocation.transaction_id', $ids)
                ->select('allocation.transaction_id', 'allocation.stage_enrollment_id', 'allocation.amount',
                    'stage.enrollment_id', 'enrollment.student_id')->get();
            foreach ($rows as $row) {
                $allocations[$row->transaction_id][] = $row;
            }
        }
        $expanded = [];
        foreach ($transactions as $transaction) {
            $parts = $allocations[$transaction->id] ?? [];
            if (! $parts) {
                $expanded[] = $transaction;

                continue;
            }
            if ($transaction->transactionamount === null) {
                Enrollments::error("Transaction {$transaction->id} has allocations but no amount.");
            }
            $total = BigDecimal::zero();
            $amount = BigDecimal::of((string) $transaction->transactionamount);
            foreach ($parts as $part) {
                if ((string) $part->student_id !== (string) $transaction->student_id
                    || (string) $part->enrollment_id !== (string) $transaction->enrollment_id) {
                    Enrollments::error("Transaction {$transaction->id} has an allocation outside its enrollment.");
                }
                $value = BigDecimal::of((string) $part->amount);
                if ($value->isNegative()) {
                    Enrollments::error("Transaction {$transaction->id} has a negative allocation.");
                }
                $total = $total->plus($value);
                $copy = clone $transaction;
                $copy->stage_enrollment_id = $part->stage_enrollment_id;
                $copy->transactionamount = (string) ($amount->isNegative() ? $value->negated() : $value);
                $expanded[] = $copy;
            }
            if (! $total->isEqualTo($amount->abs())) {
                Enrollments::error("Transaction {$transaction->id} allocations do not match its amount.");
            }
        }

        return $expanded;
    }

    public static function reportStudents(array $filters): Builder
    {
        return Student::query()->where('hasenrolled', true)->where(function (Builder $query) use ($filters) {
            $query->where(function (Builder $legacy) use ($filters) {
                $legacy->whereNull('current_enrollment_id')->where('start_date', '>=', $filters['startdate'])
                    ->where('start_date', '<', $filters['lastpossiblestartdate'])
                    ->where(fn ($q) => $q->where('graddate', '>=', $filters['startdate'])->orWhereNull('graddate'));
            })->orWhereHas('enrollments', function (Builder $enrollments) use ($filters) {
                $enrollments->where('started_on', '>=', $filters['startdate'])
                    ->where('started_on', '<', $filters['lastpossiblestartdate'])
                    ->whereDate('started_on', '<=', $filters['dateasof'])
                    ->where(function ($query) use ($filters) {
                        $query->where('mode', '!=', 'legacy')->orWhere(function ($legacy) use ($filters) {
                            $legacy->where('legacy_context->student->graddate', '>=', $filters['startdate'])
                                ->orWhereNull('legacy_context->student->graddate');
                        });
                    });
            });
        });
    }

    public static function displayProgress(Student $student, string $date): array
    {
        if (! $student->current_enrollment_id) {
            return ProgramProgress::calculate($student, $date);
        }
        $legacy = $student->enrollments->firstWhere('mode', 'legacy');
        $display = $legacy ? self::legacyStudent($student, $legacy) : $student;
        $progress = ProgramProgress::calculate($display, $date);
        // A single time ratio does not describe mixed enrollment fees.
        if (! $legacy || $legacy->ended_on->format('Y-m-d') <= $date) {
            $progress['fields']['pct'] = 'Per enrollment/stage';
        }

        return $progress;
    }
}
