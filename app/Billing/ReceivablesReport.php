<?php

namespace App\Billing;

use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

final class ReceivablesReport
{
    public const HEADERS = ['student id', 'name', 'receivable', 'program'];

    public static function run(array $filters): array
    {
        return DB::transaction(function () use ($filters) {
            $query = DB::table('studenttransactions as t')->leftJoin('students as s', 's.id', '=', 't.student_id')
                ->where(fn ($q) => $q->whereNull('s.id')->orWhere('s.hasenrolled', true))
                ->select('t.student_id', 't.transactionamount', 's.first_name', 's.middle_name', 's.last_name')
                ->orderBy('t.processeddate')->orderBy('t.id');
            if (! empty($filters['startdate'])) {
                $query->leftJoin('student_enrollments as e', 'e.id', '=', 't.enrollment_id')
                    ->whereRaw('CASE WHEN t.enrollment_id IS NULL THEN s.start_date ELSE e.started_on END >= ?', [$filters['startdate']]);
            }
            ReportPrograms::filter($query, 't.student_id', 'receivables', $filters);
            if (! empty($filters['enddate'])) {
                ReportTransactionDates::requireOurDate($query, 't.processeddate', 't.id');
                $query->where('t.processeddate', '<', $filters['enddate']);
            }
            $rows = [];
            $total = '0.00';
            foreach ($query->cursor() as $transaction) {
                $key = $transaction->student_id ?? 'unknown';
                $rows[$key] ??= ['id' => $transaction->student_id,
                    'name' => $transaction->student_id === null ? 'UNKNOWN' : ReportFormatting::name($transaction->first_name, $transaction->middle_name, $transaction->last_name),
                    'amount' => '0.00'];
                if ($transaction->transactionamount !== null) {
                    $amount = Money::cents((string) $transaction->transactionamount);
                    $rows[$key]['amount'] = Money::add($rows[$key]['amount'], $amount);
                    $total = Money::add($total, $amount);
                }
            }
            if (($filters['zeros'] ?? '') === 'hidezero') {
                $rows = array_filter($rows, fn ($row) => BigDecimal::of($row['amount'])->isPositive());
            }
            $programs = ReportPrograms::labels(array_column($rows, 'id'), 'receivables', $filters);
            foreach ($rows as &$row) {
                $row['program'] = $row['id'] === null ? 'Unknown program' : ($programs[$row['id']] ?? 'No program recorded for period');
            }
            unset($row);

            return ['filters' => $filters, 'rows' => array_values($rows), 'total' => $total];
        });
    }
}
