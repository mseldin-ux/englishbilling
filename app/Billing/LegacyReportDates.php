<?php

namespace App\Billing;

use App\Models\Student;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class LegacyReportDates
{
    public static function graduation(Builder $query, string $alias, bool $snapshot = false): string
    {
        $actual = self::field($query, $alias, 'actualdategrad', $snapshot);
        $expected = self::field($query, $alias, 'graddate', $snapshot);

        return "COALESCE($actual, $expected)";
    }

    public static function inactive(Builder $query, string $studentAlias, ?string $enrollmentAlias = null): string
    {
        $snapshot = $enrollmentAlias !== null;
        $alias = $enrollmentAlias ?? $studentAlias;
        $start = self::field($query, $alias, 'start_date', $snapshot);
        $inactive = self::field($query, $alias, 'inactive', $snapshot);
        $date = self::field($query, $alias, 'inactive_date', $snapshot);
        $graduated = self::field($query, $alias, 'graduated', $snapshot);
        $actual = self::field($query, $alias, 'actualdategrad', $snapshot);
        $graduation = self::graduation($query, $alias, $snapshot);
        $candidates = ["SELECT CASE WHEN CAST($graduated AS CHAR) IN ('1', 'true') THEN $graduation ELSE $actual END AS rd_date"];
        foreach (['pifdate', 'wd_date', 'sus_date', 'loa_date', 'extension_start_date'] as $field) {
            $candidates[] = 'SELECT '.self::field($query, $alias, $field, $snapshot);
        }
        $scope = $snapshot ? "rd_t.enrollment_id = $enrollmentAlias.id" : 'rd_t.enrollment_id IS NULL';
        $studentId = $snapshot ? "$enrollmentAlias.student_id" : "$studentAlias.id";
        $candidates[] = "SELECT rd_t.processeddate FROM studenttransactions AS rd_t WHERE rd_t.student_id = $studentId AND $scope";
        if ($snapshot) {
            $context = $query->getGrammar()->wrap("$alias.legacy_context");
            if ($query->getConnection()->getDriverName() === 'sqlite') {
                $leaves = "json_each($context, '$.loas')";
                $candidates[] = "SELECT json_extract(value, '$.loa_start') FROM $leaves";
                $candidates[] = "SELECT json_extract(value, '$.loa_end') FROM $leaves";
            } else {
                $leaves = "JSON_TABLE($context, '$.loas[*]' COLUMNS (loa_start VARCHAR(40) PATH '$.loa_start', loa_end VARCHAR(40) PATH '$.loa_end')) AS rd_l";
                $candidates[] = "SELECT rd_l.loa_start FROM $leaves";
                $candidates[] = "SELECT rd_l.loa_end FROM $leaves";
            }
        } else {
            $candidates[] = "SELECT rd_l.loa_start FROM loas AS rd_l WHERE rd_l.student_id = $studentAlias.id AND rd_l.enrollment_id IS NULL";
            $candidates[] = "SELECT rd_l.loa_end FROM loas AS rd_l WHERE rd_l.student_id = $studentAlias.id AND rd_l.enrollment_id IS NULL";
        }
        $latest = '(SELECT MAX(rd_date) FROM ('.implode(' UNION ALL ', $candidates).') AS rd_dates)';
        $missing = "EXISTS (SELECT 1 FROM studenttransactions AS rd_t WHERE rd_t.student_id = $studentId AND $scope AND rd_t.processeddate IS NULL)";

        return "CASE WHEN $date IS NOT NULL THEN $date WHEN CAST($inactive AS CHAR) IN ('1', 'true') AND $start < '2022-01-01' AND NOT $missing AND $latest < '2022-01-01' THEN $latest ELSE NULL END";
    }

    public static function load(Collection $students): void
    {
        if ($students->isEmpty()) {
            return;
        }
        foreach ($students->chunk(300) as $chunk) {
            $query = DB::table('students as rd_s')->leftJoin('student_enrollments as rd_e', function ($join) {
                $join->on('rd_e.student_id', '=', 'rd_s.id')->where('rd_e.mode', '=', 'legacy');
            })->whereIn('rd_s.id', $chunk->modelKeys());
            $rows = $query->select('rd_s.id', 'rd_e.id as enrollment_id')
                ->selectRaw(self::inactive($query, 'rd_s').' AS report_current_date')
                ->selectRaw(self::inactive($query, 'rd_s', 'rd_e').' AS report_legacy_date')->get()->groupBy('id');
            foreach ($chunk as $student) {
                $records = $rows->get($student->id, collect());
                $student->setRelation('reportStatusDates', collect([
                    'current' => $records->first()?->report_current_date,
                    'legacy' => $records->whereNotNull('enrollment_id')->pluck('report_legacy_date', 'enrollment_id'),
                ]));
            }
        }
    }

    public static function forStudent(Student $student): ?string
    {
        if (! $student->relationLoaded('reportStatusDates')) {
            self::load(new Collection([$student]));
        }

        return $student->getRelation('reportStatusDates')->get('current');
    }

    public static function forSnapshot(Student $student, int $enrollmentId): ?string
    {
        if (! $student->relationLoaded('reportStatusDates')) {
            self::load(new Collection([$student]));
        }

        return $student->getRelation('reportStatusDates')->get('legacy')->get($enrollmentId);
    }

    private static function field(Builder $query, string $alias, string $field, bool $snapshot): string
    {
        $column = $snapshot ? "$alias.legacy_context->student->$field" : "$alias.$field";

        $wrapped = $query->getGrammar()->wrap($column);

        return $snapshot ? "NULLIF($wrapped, 'null')" : $wrapped;
    }
}
