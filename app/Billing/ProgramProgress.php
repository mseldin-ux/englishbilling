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
        $inactive = $student->getRawOriginal('inactive_date');
        if ($student->inactive && $inactive === null) {
            $inactive = LegacyReportDates::forStudent($student);
        }
        $inactiveDate = self::date($inactive);
        $force = $student->archived && $start < CarbonImmutable::parse('2022-01-01', 'UTC')
            && (! $student->inactive || $inactiveDate === null || $inactiveDate <= $asOf);
        $lastDates = [$grad];
        foreach (['wd' => 'wd_date', 'inactive' => 'inactive_date', 'onextension' => 'extension_start_date',
            'sus' => 'sus_date', 'graduated' => 'actualdategrad'] as $flag => $field) {
            $raw = $flag === 'inactive' ? $inactive : $student->getRawOriginal($field);
            $event = self::date($raw);
            if ($student->$flag && $event !== null) {
                $lastDates[] = $event;
                if ($flag !== 'sus' && $event <= $asOf) {
                    $force = true;
                }
            }
        }
        if ($student->graduated && $student->actualdategrad === null && $student->graddate !== null && $grad <= $asOf) {
            $force = true;
        }
        $last = min($lastDates);
        $loaSeconds = LeavePeriods::seconds($student, $asOf);
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
