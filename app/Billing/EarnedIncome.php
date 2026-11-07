<?php

namespace App\Billing;

use App\Models\Student;
use Brick\Math\BigDecimal;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

final class EarnedIncome
{
    public const FIELDS = ['id', 'first_name', 'last_name', 'archived', 'cohort', 'currentservicetime',
        'extension_start_date', 'graduated', 'graddate', 'actualdategrad', 'inactive', 'inactive_date',
        'lastdate', 'lengthofprogram', 'loalength', 'onextension', 'pif', 'pifdate', 'program_id',
        'start_date', 'sus', 'sus_date', 'totaltimeinprogram', 'wd', 'wd_date', 'invoiced', 'pct', 'earned', 'unearned'];

    public static function run(array $filters): array
    {
        return DB::transaction(function () use ($filters) {
            $students = Student::where('start_date', '>=', $filters['startdate'])
                ->where('start_date', '<', $filters['lastpossiblestartdate'])
                ->where(fn ($query) => $query->where('graddate', '>=', $filters['startdate'])->orWhereNull('graddate'))
                ->with(['program', 'loas'])->orderBy('id')->get();
            $fees = [];
            foreach (array_chunk($students->modelKeys(), 300) as $ids) {
                $query = DB::table('studenttransactions')->whereIn('student_id', $ids)
                    ->whereIn(DB::raw('LOWER(transactiontype)'), ['fee', 'declined fee'])
                    ->whereNotNull('transactionamount')->select('student_id', 'transactionamount')->orderBy('id');
                foreach ($query->cursor() as $transaction) {
                    $amount = (string) BigDecimal::of((string) $transaction->transactionamount)->abs()->toScale(2);
                    $fees[$transaction->student_id] = Money::add($fees[$transaction->student_id] ?? '0.00', $amount);
                }
            }
            $rows = [];
            $totalInvoiced = '0.00';
            $totalEarned = $totalUnearned = BigRational::of(0);
            foreach ($students as $student) {
                $progress = ProgramProgress::calculate($student, $filters['dateasof']);
                // The legacy "skip zero balance" option actually excludes zero service ratios.
                if (($filters['skipzero'] ?? '') === 'yes' && $progress['ratio']->isZero()) {
                    continue;
                }
                $invoiced = $fees[$student->id] ?? '0.00';
                $earned = BigRational::of($invoiced)->multipliedBy($progress['ratio']);
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
