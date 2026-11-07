<?php

namespace App\Billing;

use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class InvoicesReport
{
    public const HEADERS = ['Student ID', 'Type', 'Name', 'Invoice ID', 'Date Sent', 'Date Received', 'Note',
        'Amount', 'SUBTOTAL SENT', 'SUBTOTAL RECEIVED', 'SUBTOTAL PREPAYMENT', 'SUBTOTAL OUTSTANDING'];

    public static function run(array $filters): array
    {
        return DB::transaction(function () use ($filters) {
            $query = DB::table('invoices as i')->leftJoin('students as s', 's.id', '=', 'i.student_id')
                ->select('i.id', 'i.student_id', 'i.datesent', 'i.amount', 's.first_name', 's.middle_name', 's.last_name')
                ->orderBy('i.student_id')->orderBy('i.datesent')->orderBy('i.id');
            foreach (['studentenrolleddate' => ['s.start_date', '>='], 'invoicesentafterdate' => ['i.datesent', '>='],
                'invoicesentbeforedate' => ['i.datesent', '<=']] as $filter => [$column, $operator]) {
                if (isset($filters[$filter])) {
                    $query->where($column, $operator, $filters[$filter].' 00:00:00');
                }
            }
            foreach (['hidegraduated' => 's.graduated', 'hideinactive' => 's.inactive'] as $filter => $column) {
                if (! empty($filters[$filter])) {
                    $query->where(fn ($q) => $q->whereNull($column)->orWhere($column, false));
                }
            }
            $groups = [];
            $totals = ['Total invoiced' => '0.00', 'Total received' => '0.00', 'Total prepayments' => '0.00', 'Total outstanding' => '0.00'];
            foreach ($query->cursor() as $invoice) {
                if ($invoice->amount === null) {
                    throw ValidationException::withMessages(['report' => "Invoice {$invoice->id} has a missing amount. Correct it before generating this report."]);
                }
                $key = $invoice->student_id === null ? 'unknown' : (string) $invoice->student_id;
                $groups[$key] ??= ['id' => $invoice->student_id, 'invoices' => [], 'payments' => [],
                    'invoiced' => '0.00', 'received' => '0.00', 'prepayment' => '0.00', 'outstanding' => '0.00'];
                $amount = Money::cents((string) $invoice->amount);
                $absolute = (string) BigDecimal::of($amount)->abs();
                $groups[$key]['invoiced'] = Money::add($groups[$key]['invoiced'], $absolute);
                $totals['Total invoiced'] = Money::add($totals['Total invoiced'], $absolute);
                $groups[$key]['invoices'][] = ['id' => $invoice->id, 'name' => $invoice->student_id === null ? 'UNKNOWN' : ReportFormatting::name($invoice->first_name, $invoice->middle_name, $invoice->last_name),
                    'date' => ReportFormatting::date($invoice->datesent), 'amount' => $amount];
            }
            if (! empty($filters['showreceived'])) {
                $ids = array_column($groups, 'id');
                foreach (array_chunk(array_filter($ids, fn ($id) => $id !== null), 300) as $chunk) {
                    $payments = DB::table('studenttransactions')->whereIn('student_id', $chunk)
                        ->where(fn ($q) => $q->whereRaw("LOWER(transactiontype) LIKE '%payment%'")->orWhereRaw("LOWER(transactiontype) LIKE '%credit%'"))
                        ->select('id', 'student_id', 'transactiontype', 'transactiondate', 'transactionamount')
                        ->orderBy('transactiondate')->orderBy('id');
                    // Legacy uses customer dates for the lower bound and processed dates for the upper.
                    if (! empty($filters['invoicesentafterdate'])) {
                        $payments->where('transactiondate', '>=', $filters['invoicesentafterdate']);
                    }
                    if (! empty($filters['invoicesentbeforedate'])) {
                        $payments->where('processeddate', '<=', $filters['invoicesentbeforedate'].' 00:00:00');
                    }
                    foreach ($payments->cursor() as $payment) {
                        if ($payment->transactionamount === null) {
                            throw ValidationException::withMessages(['report' => "Transaction {$payment->id} has a missing amount. Correct it before generating this report."]);
                        }
                        $amount = Money::cents((string) $payment->transactionamount);
                        $groups[(string) $payment->student_id]['received'] = Money::add($groups[(string) $payment->student_id]['received'], (string) BigDecimal::of($amount)->abs());
                        $groups[(string) $payment->student_id]['payments'][] = ['id' => $payment->id, 'type' => $payment->transactiontype,
                            'date' => ReportFormatting::date($payment->transactiondate), 'amount' => $amount];
                    }
                }
                foreach ($groups as &$group) {
                    $outstanding = BigDecimal::of($group['invoiced'])->minus($group['received']);
                    $group['prepayment'] = $outstanding->isNegative() ? (string) $outstanding->abs() : '0.00';
                    $group['outstanding'] = $outstanding->isNegative() ? '0.00' : (string) $outstanding;
                    $totals['Total received'] = Money::add($totals['Total received'], $group['received']);
                    $totals['Total prepayments'] = Money::add($totals['Total prepayments'], $group['prepayment']);
                }
                unset($group);
            }
            $totals['Total outstanding'] = (string) BigDecimal::of($totals['Total invoiced'])->minus($totals['Total received']);
            ksort($groups, SORT_STRING);

            return ['filters' => $filters, 'groups' => array_values($groups), 'totals' => $totals];
        });
    }
}
