<?php

namespace App\Billing;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TransactionsReport
{
    public const HEADERS = ['transaction id', 'student id', 'name', 'transaction date',
        'processed date', 'type', 'amount', 'notes'];

    public static function types(): array
    {
        return DB::table('studenttransactions')->whereNotNull('transactiontype')
            ->distinct()->orderBy('transactiontype')->pluck('transactiontype')->all();
    }

    public static function run(array $filters): array
    {
        return DB::transaction(function () use ($filters) {
            $query = DB::table('studenttransactions as t')->leftJoin('students as s', 's.id', '=', 't.student_id')
                ->where(fn ($q) => $q->whereNull('s.id')->orWhere('s.hasenrolled', true))
                ->select('t.id', 't.student_id', 't.transactiondate', 't.processeddate', 't.transactiontype',
                    't.transactionamount', 't.transactionnotes', 's.first_name', 's.middle_name', 's.last_name')
                ->orderBy('t.processeddate')->orderBy('t.id');
            foreach (['transmin' => '>=', 'transmax' => '<='] as $field => $operator) {
                if (isset($filters[$field])) {
                    $query->where('t.transactionamount', $operator, $filters[$field]);
                }
            }
            $which = $filters['whichtransactions'] ?? 'all';
            if ($which !== 'all' && $which !== '') {
                $type = match ($which) {
                    'feesonly' => 'fee',
                    'paymentsonly' => 'payment',
                    default => $which,
                };
                $query->whereRaw("LOWER(t.transactiontype) LIKE LOWER(?) ESCAPE '!'", [
                    '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $type).'%',
                ]);
            }
            ReportPrograms::filter($query, 't.student_id', 'transactions', $filters);
            if (! empty($filters['startdate']) || ! empty($filters['enddate'])) {
                ReportTransactionDates::requireOurDate($query, 't.processeddate', 't.id');
                foreach (['startdate' => '>=', 'enddate' => '<'] as $field => $operator) {
                    if (! empty($filters[$field])) {
                        $query->where('t.processeddate', $operator, $filters[$field]);
                    }
                }
            }
            $rows = $totals = [];
            foreach ($query->cursor() as $transaction) {
                if ($transaction->transactionamount === null) {
                    throw ValidationException::withMessages(['report' => "Transaction {$transaction->id} has a missing amount. Correct it before generating this report."]);
                }
                $amount = Money::cents((string) $transaction->transactionamount);
                $type = $transaction->transactiontype ?? 'None';
                $totals[$type] = Money::add($totals[$type] ?? '0.00', $amount);
                $rows[] = ['id' => $transaction->id, 'student_id' => $transaction->student_id,
                    'name' => $transaction->student_id === null ? 'UNKNOWN' : ReportFormatting::name($transaction->first_name, $transaction->middle_name, $transaction->last_name),
                    'date' => ReportFormatting::date($transaction->transactiondate),
                    'processed' => ReportFormatting::date($transaction->processeddate), 'type' => $transaction->transactiontype ?? '',
                    'amount' => $amount, 'notes' => $transaction->transactionnotes ?? ''];
            }
            ksort($totals, SORT_STRING);

            return ['filters' => $filters, 'rows' => $rows, 'totals' => $totals];
        });
    }
}
