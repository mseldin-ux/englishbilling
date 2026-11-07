<?php

namespace App\Billing;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ExpectedIncome
{
    public const HEADERS = ['Payment Due Date', 'Student Id', 'First', 'Last', 'Amount', 'Notes'];

    public static function run(array $filters): array
    {
        return DB::transaction(function () use ($filters) {
            $query = DB::table('anticipatedpayment as p')->leftJoin('students as s', 's.id', '=', 'p.student_id')
                ->select('p.id', 'p.student_id', 'p.paymentduedate', 'p.paymentamount', 'p.paymentnotes',
                    's.first_name', 's.middle_name', 's.last_name')
                ->orderBy('p.paymentduedate')->orderBy('p.id');
            foreach (['startdate' => '>=', 'enddate' => '<'] as $field => $operator) {
                if (! empty($filters[$field])) {
                    $query->where('p.paymentduedate', $operator, $filters[$field]);
                }
            }
            $rows = $months = [];
            $total = '0.00';
            foreach ($query->cursor() as $payment) {
                if ($payment->paymentduedate === null || $payment->paymentamount === null) {
                    throw ValidationException::withMessages(['report' => "Scheduled payment {$payment->id} has a missing due date or amount. Correct it before generating this report."]);
                }
                $amount = Money::cents((string) $payment->paymentamount);
                $month = substr($payment->paymentduedate, 0, 4).' '.substr($payment->paymentduedate, 5, 2);
                $months[$month] = Money::add($months[$month] ?? '0.00', $amount);
                $total = Money::add($total, $amount);
                $rows[] = ['date' => ReportFormatting::date($payment->paymentduedate),
                    'student_id' => $payment->student_id, 'first' => $payment->first_name ?? '',
                    'last' => $payment->last_name ?? '',
                    'name' => $payment->student_id === null ? 'UNKNOWN' : ReportFormatting::name($payment->first_name, $payment->middle_name, $payment->last_name),
                    'amount' => $amount, 'notes' => $payment->paymentnotes ?? ''];
            }
            ksort($months, SORT_STRING);

            return ['filters' => $filters, 'rows' => $rows, 'months' => $months, 'total' => $total];
        });
    }
}
