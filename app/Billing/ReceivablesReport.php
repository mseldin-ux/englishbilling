<?php

namespace App\Billing;

use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

final class ReceivablesReport
{
    public const HEADERS = ['student id', 'name', 'receivable'];

    public static function run(array $filters): array
    {
        return DB::transaction(function () use ($filters) {
            $query = DB::table('studenttransactions as t')->leftJoin('students as s', 's.id', '=', 't.student_id')
                ->select('t.student_id', 't.transactionamount', 's.first_name', 's.middle_name', 's.last_name')
                ->orderBy('t.transactiondate')->orderBy('t.id');
            if (! empty($filters['startdate'])) {
                $query->where('s.start_date', '>=', $filters['startdate']);
            }
            if (! empty($filters['enddate'])) {
                $query->where('t.transactiondate', '<', $filters['enddate'])->where('t.processeddate', '<', $filters['enddate']);
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

            return ['filters' => $filters, 'rows' => array_values($rows), 'total' => $total];
        });
    }
}
