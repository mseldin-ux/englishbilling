<?php

namespace App\Billing;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class ReportPrograms
{
    public static function period(string $report, array $filters): array
    {
        if (in_array($report, ['collections', 'receivables', 'earned', 'prepays'], true)) {
            $date = match ($report) {
                'receivables' => ! empty($filters['enddate']) ? CarbonImmutable::parse($filters['enddate'])->subDay() : today(),
                'earned' => CarbonImmutable::parse($filters['dateasof']),
                'prepays' => CarbonImmutable::parse($filters['dateasof'])->subDay(),
                default => today(),
            };

            return [$date->toDateString(), $date->addDay()->toDateString()];
        }
        if ($report === 'invoices') {
            return [$filters['invoicesentafterdate'] ?? null,
                ! empty($filters['invoicesentbeforedate']) ? CarbonImmutable::parse($filters['invoicesentbeforedate'])->addDay()->toDateString() : null];
        }

        return [$filters['startdate'] ?? null, $filters['enddate'] ?? null];
    }

    public static function filter(Builder $query, string $studentColumn, string $report, array $filters): void
    {
        if (empty($filters['program_id'])) {
            return;
        }
        [$from, $before] = self::period($report, $filters);
        $programId = (int) $filters['program_id'];
        $query->where(function (Builder $matches) use ($studentColumn, $from, $before, $programId) {
            $matches->whereExists(function (Builder $enrollments) use ($studentColumn, $from, $before, $programId) {
                $enrollments->selectRaw('1')->from('student_enrollments as rp_e')
                    ->join('programs as rp_p', 'rp_p.id', '=', 'rp_e.program_id')
                    ->whereColumn('rp_e.student_id', $studentColumn)
                    ->whereRaw('COALESCE(rp_p.certificate_program_id, rp_p.id) = ?', [$programId]);
                self::enrollmentDates($enrollments, $from, $before);
            })->orWhereExists(function (Builder $legacy) use ($studentColumn, $from, $before, $programId) {
                $legacy->selectRaw('1')->from('students as rp_s')
                    ->join('programs as rp_p', 'rp_p.id', '=', 'rp_s.program_id')
                    ->whereColumn('rp_s.id', $studentColumn)->whereNull('rp_s.current_enrollment_id')
                    ->whereRaw('COALESCE(rp_p.certificate_program_id, rp_p.id) = ?', [$programId]);
                self::legacyDates($legacy, $from, $before);
            });
        });
    }

    public static function labels(array $studentIds, string $report, array $filters): array
    {
        [$from, $before] = self::period($report, $filters);
        $labels = [];
        foreach (array_chunk(array_values(array_unique(array_filter($studentIds, fn ($id) => $id !== null))), 300) as $ids) {
            $enrollments = DB::table('student_enrollments as rp_e')
                ->leftJoin('programs as rp_p', 'rp_p.id', '=', 'rp_e.program_id')
                ->leftJoin('programs as rp_certificate', 'rp_certificate.id', '=', 'rp_p.certificate_program_id')
                ->whereIn('rp_e.student_id', $ids)
                ->select('rp_e.student_id', 'rp_e.mode', 'rp_e.started_on', 'rp_e.ended_on')
                ->selectRaw('COALESCE(rp_certificate.program, rp_p.program) as program_name');
            self::enrollmentDates($enrollments, $from, $before);
            foreach ($enrollments->get() as $enrollment) {
                $incomplete = $enrollment->mode === 'legacy' || $enrollment->started_on === null;
                $name = $enrollment->program_name ?: 'Unknown program';
                $labels[$enrollment->student_id][$name] = ($labels[$enrollment->student_id][$name] ?? false) || $incomplete;
            }
            $legacy = DB::table('students as rp_s')
                ->leftJoin('programs as rp_p', 'rp_p.id', '=', 'rp_s.program_id')
                ->leftJoin('programs as rp_certificate', 'rp_certificate.id', '=', 'rp_p.certificate_program_id')
                ->whereIn('rp_s.id', $ids)->whereNull('rp_s.current_enrollment_id')
                ->select('rp_s.id')->selectRaw('COALESCE(rp_certificate.program, rp_p.program) as program_name');
            self::legacyDates($legacy, $from, $before);
            foreach ($legacy->get() as $student) {
                $labels[$student->id][$student->program_name ?: 'Unknown program'] = true;
            }
        }
        foreach ($labels as $id => $programs) {
            ksort($programs, SORT_NATURAL | SORT_FLAG_CASE);
            $labels[$id] = implode('; ', array_map(
                fn ($name, $incomplete) => $name.($incomplete ? ' (history incomplete)' : ''),
                array_keys($programs), array_values($programs)
            ));
        }

        return $labels;
    }

    private static function enrollmentDates(Builder $query, ?string $from, ?string $before): void
    {
        if ($before) {
            $query->where(fn ($q) => $q->whereNull('rp_e.started_on')->orWhere('rp_e.started_on', '<', $before));
        }
        if ($from) {
            $query->where(fn ($q) => $q->whereNull('rp_e.ended_on')->orWhere('rp_e.ended_on', '>=', $from));
            $query->where(function ($q) use ($from) {
                $q->where('rp_e.mode', '!=', 'legacy')->orWhere(function ($legacy) use ($from) {
                    foreach (['graduated' => 'actualdategrad', 'wd' => 'wd_date', 'sus' => 'sus_date', 'inactive' => 'inactive_date'] as $flag => $date) {
                        $column = "rp_e.legacy_context->student->$flag";
                        $wrapped = $legacy->getGrammar()->wrap($column);
                        $effective = match ($flag) {
                            'graduated' => LegacyReportDates::graduation($legacy, 'rp_e', true),
                            'inactive' => LegacyReportDates::inactive($legacy, 'rp_e', 'rp_e'),
                            default => $legacy->getGrammar()->wrap("rp_e.legacy_context->student->$date"),
                        };
                        $legacy->where(fn ($q) => $q->whereNull($column)
                            ->orWhereRaw("CAST($wrapped AS CHAR) IN (?, ?)", ['0', 'false'])
                            ->orWhereRaw("($effective) IS NULL")
                            ->orWhereRaw("($effective) >= ?", [$from]));
                    }
                });
            });
        }
    }

    private static function legacyDates(Builder $query, ?string $from, ?string $before): void
    {
        if ($before) {
            $query->where(fn ($q) => $q->whereNull('rp_s.start_date')->orWhere('rp_s.start_date', '<', $before));
        }
        if ($from) {
            foreach (['graduated' => 'actualdategrad', 'wd' => 'wd_date', 'sus' => 'sus_date', 'inactive' => 'inactive_date'] as $flag => $date) {
                $effective = match ($flag) {
                    'graduated' => LegacyReportDates::graduation($query, 'rp_s'),
                    'inactive' => LegacyReportDates::inactive($query, 'rp_s'),
                    default => $query->getGrammar()->wrap("rp_s.$date"),
                };
                $query->where(fn ($q) => $q->whereNull("rp_s.$flag")->orWhere("rp_s.$flag", false)
                    ->orWhereRaw("($effective) IS NULL")->orWhereRaw("($effective) >= ?", [$from]));
            }
        }
    }
}
