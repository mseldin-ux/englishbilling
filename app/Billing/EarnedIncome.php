<?php

namespace App\Billing;

use Brick\Math\BigDecimal;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class EarnedIncome
{
    public const FIELDS = ['id', 'first_name', 'last_name', 'archived', 'cohort', 'currentservicetime',
        'extension_start_date', 'graduated', 'graddate', 'actualdategrad', 'inactive', 'inactive_date',
        'lastdate', 'lengthofprogram', 'loalength', 'onextension', 'pif', 'pifdate', 'program_id',
        'start_date', 'sus', 'sus_date', 'totaltimeinprogram', 'wd', 'wd_date', 'invoiced', 'pct', 'earned', 'unearned', 'program'];

    public static function run(array $filters): array
    {
        return DB::transaction(function () use ($filters) {
            $query = EnrollmentAccounting::reportStudents($filters)->with(['program', 'loas'])->orderBy('id');
            ReportPrograms::filter($query->getQuery(), 'students.id', 'earned', $filters);
            $students = $query->get();
            LegacyReportDates::load($students);
            $programs = ReportPrograms::labels($students->modelKeys(), 'earned', $filters);
            $students->filter(fn ($student) => $student->current_enrollment_id)->load('enrollments.stages', 'enrollments.events');
            $fees = $transactions = [];
            foreach (array_chunk($students->modelKeys(), 300) as $ids) {
                $query = DB::table('studenttransactions')->whereIn('student_id', $ids)
                    ->whereIn(DB::raw('LOWER(transactiontype)'), ['fee', 'declined fee'])
                    ->whereNotNull('transactionamount')->select('id', 'student_id', 'transactionamount', 'transactiontype',
                        'processeddate', 'enrollment_id', 'stage_enrollment_id', 'recognition')->orderBy('id')
                    ->where(function ($query) use ($filters) {
                        $query->whereNull('processeddate')->orWhere('processeddate', '<', $filters['dateasof']);
                    });
                $batch = $query->get();
                foreach ($batch as $transaction) {
                    if ($transaction->processeddate === null) {
                        throw ValidationException::withMessages(['report' => "Fee transaction {$transaction->id} has no Our Date. Correct it before generating Earned Income."]);
                    }
                    $amount = (string) BigDecimal::of((string) $transaction->transactionamount)->abs()->toScale(2);
                    $fees[$transaction->student_id] = Money::add($fees[$transaction->student_id] ?? '0.00', $amount);
                }
                foreach (EnrollmentAccounting::expandAllocations($batch) as $transaction) {
                    $transactions[$transaction->student_id][] = $transaction;
                }
            }
            $rows = [];
            $totalInvoiced = '0.00';
            $totalEarned = $totalUnearned = BigRational::of(0);
            foreach ($students as $student) {
                $progress = EnrollmentAccounting::displayProgress($student, $filters['dateasof']);
                // The legacy "skip zero balance" option actually excludes zero service ratios.
                if (! $student->current_enrollment_id && ($filters['skipzero'] ?? '') === 'yes' && $progress['ratio']->isZero()) {
                    continue;
                }
                $invoiced = $fees[$student->id] ?? '0.00';
                $earned = EnrollmentAccounting::earned($student, $transactions[$student->id] ?? [], $filters['dateasof'],
                    fn (array $types) => Money::add($types['fee'] ?? '0.00', $types['declined fee'] ?? '0.00'));
                if ($student->current_enrollment_id && ($filters['skipzero'] ?? '') === 'yes' && $earned->isZero()) {
                    continue;
                }
                $unearned = BigRational::of($invoiced)->minus($earned);
                $totalInvoiced = Money::add($totalInvoiced, $invoiced);
                $totalEarned = $totalEarned->plus($earned);
                $totalUnearned = $totalUnearned->plus($unearned);
                $row = [];
                foreach (self::FIELDS as $field) {
                    $row[$field] = $progress['fields'][$field] ?? ($student->getRawOriginal($field) === null ? '' : (string) $student->getRawOriginal($field));
                    if ($row[$field] === 'None' && $student->getRawOriginal($field) === null) {
                        $row[$field] = '';
                    }
                }
                $row['extension_start_date'] = ReportFormatting::date($student->getRawOriginal('extension_start_date'));
                $row['invoiced'] = $invoiced;
                $row['earned'] = (string) $earned->toScale(6, RoundingMode::HalfUp);
                $row['unearned'] = (string) $unearned->toScale(6, RoundingMode::HalfUp);
                $row['program'] = $programs[$student->id] ?? 'No program recorded for period';
                $rows[] = $row;
            }
            $sort = match ($filters['sort'] ?? '') {
                'Last Name' => 'last_name',
                'Graduation Date' => 'graddate',
                default => 'id',
            };
            usort($rows, fn ($a, $b) => ($sort === 'id' ? (int) $a[$sort] <=> (int) $b[$sort] : strcmp($a[$sort], $b[$sort])) ?: (int) $a['id'] <=> (int) $b['id']);

            return ['filters' => $filters, 'rows' => $rows, 'totals' => [
                'invoiced' => $totalInvoiced, 'total earned' => (string) $totalEarned->toScale(2, RoundingMode::HalfUp),
                'total unearned' => (string) $totalUnearned->toScale(2, RoundingMode::HalfUp)]];
        });
    }
}
