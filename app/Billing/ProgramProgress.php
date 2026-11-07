<?php

namespace App\Billing;

use App\Models\Student;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class ProgramProgress
{
    public const FIELDS = ['loa', 'loalength', 'currentservicetime', 'lengthofprogram', 'pct',
        'totaltimeinprogram', 'start_date', 'graduated', 'graddate', 'actualdategrad', 'pif',
        'pifdate', 'inactive', 'inactive_date', 'wd', 'wd_date', 'sus', 'sus_date', 'lastdate', 'archived'];

    public static function calculate(Student $student, string $date): array
    {
        // Legacy datetimes are naive calendar values; UTC arithmetic avoids introducing DST shifts.
        $asOf = CarbonImmutable::parse($date, 'UTC')->startOfDay();
        $start = self::date($student->getRawOriginal('start_date'));
        $grad = self::date($student->getRawOriginal('graddate'));
        if ($start === null && $grad === null) {
            $grad = $asOf;
        }
        $start ??= $grad->subYearsNoOverflow(3);
        $grad ??= $start->addYearsNoOverflow(3);
        $force = $student->archived && $start < CarbonImmutable::parse('2022-01-01', 'UTC');
        $lastDates = [$grad];
        foreach (['wd' => 'wd_date', 'inactive' => 'inactive_date', 'onextension' => 'extension_start_date',
            'sus' => 'sus_date', 'graduated' => 'actualdategrad'] as $flag => $field) {
            $event = self::date($student->getRawOriginal($field));
            if ($student->$flag && $event !== null) {
                $lastDates[] = $event;
                if ($flag !== 'sus' && $event <= $asOf) {
                    $force = true;
                }
            }
        }
        if ($student->graduated && $student->graddate !== null && $grad <= $asOf) {
            $force = true;
        }
        $last = min($lastDates);
        $loaSeconds = 0;
        $loas = $student->loas->sortBy('id')->values();
        foreach ($loas as $index => $loa) {
            $begin = self::date($loa->getRawOriginal('loa_start'));
            $end = self::date($loa->getRawOriginal('loa_end'));
            if ($begin === null || $begin >= $asOf) {
                continue;
            }
            // Preserve the legacy last-row clipping rule; do not silently merge overlapping LOAs.
            if (($end === null || $end > $asOf) && $index === $loas->count() - 1) {
                $end = $asOf;
            }
            if ($end !== null) {
                $loaSeconds += max(0, $end->getTimestamp() - $begin->getTimestamp());
            }
        }
        $days = $student->program?->servicedays ?? 547;
        if ($days <= 0) {
            throw ValidationException::withMessages(['program' => "Learner {$student->id} has a program with non-positive service days. Correct it before reporting."]);
        }
        $length = (int) $days * 86400;
        $total = $asOf->getTimestamp() - $start->getTimestamp();
        $service = max(0, $total - $loaSeconds);
        $ratio = $force || $service >= $length ? BigRational::of(1) : BigRational::ofFraction($service, $length);
        $fields = [];
        foreach (self::FIELDS as $field) {
            $raw = $student->getRawOriginal($field);
            $fields[$field] = $raw === null ? 'None' : (string) $raw;
            if ($raw !== null && in_array($field, ['start_date', 'graddate', 'actualdategrad',
                'pifdate', 'inactive_date', 'wd_date', 'sus_date'], true)) {
                $parsed = self::date($raw);
                $fields[$field] = $parsed->format('Y-m-d H:i:s').($parsed->format('u') === '000000' ? '' : '.'.$parsed->format('u'));
            }
        }
        $fields['loalength'] = ReportFormatting::duration($loaSeconds);
        $fields['currentservicetime'] = ReportFormatting::duration($service);
        $fields['currenttimeinprogram'] = $fields['currentservicetime'];
        $fields['lengthofprogram'] = ReportFormatting::duration($length);
        $fields['totaltimeinprogram'] = ReportFormatting::duration($total);
        $fields['lastdate'] = $last->format('Y-m-d H:i:s');
        $fields['pct'] = (string) $ratio->toScale(12, RoundingMode::HalfUp)->strippedOfTrailingZeros();

        return ['fields' => $fields, 'ratio' => $ratio];
    }

    private static function date(?string $value): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse($value, 'UTC');
    }
}
