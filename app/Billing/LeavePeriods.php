<?php

namespace App\Billing;

use App\Models\LeaveOfAbsence;
use App\Models\Student;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class LeavePeriods
{
    public static function seconds(Student $student, CarbonImmutable $asOf, ?CarbonImmutable $missingStart = null): int
    {
        $intervals = [];
        foreach ($student->loas as $leave) {
            $rawStart = $leave->getRawOriginal('loa_start');
            $rawEnd = $leave->getRawOriginal('loa_end');
            if ($rawStart === null && $rawEnd === null) {
                continue;
            }
            $start = $rawStart === null ? $missingStart : CarbonImmutable::parse($rawStart, 'UTC');
            if ($start === null || $start >= $asOf) {
                continue;
            }
            $end = $rawEnd === null ? $asOf : CarbonImmutable::parse($rawEnd, 'UTC');
            if ($end < $start) {
                self::invalidDates($student, $leave);
            }
            $end = min($end, $asOf);
            if ($end > $start) {
                $intervals[] = [$start->getTimestamp(), $end->getTimestamp()];
            }
        }
        sort($intervals);
        $seconds = 0;
        $lastEnd = null;
        foreach ($intervals as [$start, $end]) {
            $seconds += max(0, $end - max($lastEnd ?? $start, $start));
            $lastEnd = max($lastEnd ?? $end, $end);
        }

        return $seconds;
    }

    public static function invalidDates(Student $student, LeaveOfAbsence $leave): never
    {
        $name = trim(ReportFormatting::name($student->first_name, $student->middle_name, $student->last_name));
        $errors = ['report' => "Leave {$leave->id} for $name (learner {$student->id}) ends before it starts. Correct it before reporting."];
        if ($student->id !== null) {
            $errors["report_student.{$student->id}"] = 'Open learner record';
        }

        throw ValidationException::withMessages($errors);
    }
}
