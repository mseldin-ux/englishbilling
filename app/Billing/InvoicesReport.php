<?php

namespace App\Billing;

use App\Models\StudentEnrollment;
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
                ->where(fn ($q) => $q->whereNull('s.id')->orWhere('s.hasenrolled', true))
                ->leftJoin('student_enrollments as e', 'e.id', '=', 'i.enrollment_id')
                ->select('i.id', 'i.student_id', 'i.datesent', 'i.amount', 's.first_name', 's.middle_name', 's.last_name',
                    's.graduated', 's.actualdategrad', 's.graddate', 's.inactive', 's.inactive_date')
                ->orderBy('i.student_id')->orderBy('i.datesent')->orderBy('i.id');
            $query->selectRaw(LegacyReportDates::inactive($query, 's').' AS report_inactive_date')
                ->selectRaw(LegacyReportDates::inactive($query, 's', 'e').' AS report_legacy_inactive_date');
            foreach (['studentenrolleddate' => ['s.start_date', '>='], 'invoicesentafterdate' => ['i.datesent', '>='],
                'invoicesentbeforedate' => ['i.datesent', '<=']] as $filter => [$column, $operator]) {
                if (isset($filters[$filter])) {
                    if ($filter === 'studentenrolleddate') {
                        $query->whereRaw('CASE WHEN i.enrollment_id IS NULL THEN s.start_date ELSE e.started_on END >= ?', [$filters[$filter]]);
                    } else {
                        $query->where($column, $operator, $filters[$filter].' 00:00:00');
                    }
                }
            }
            ReportPrograms::filter($query, 'i.student_id', 'invoices', $filters);
            $enrollments = null;
            $query->addSelect('i.enrollment_id');
            $asOf = $filters['invoicesentbeforedate'] ?? today()->toDateString();
            $groups = [];
            $totals = ['Total invoiced' => '0.00', 'Total received' => '0.00', 'Total prepayments' => '0.00', 'Total outstanding' => '0.00'];
            foreach ($query->cursor() as $invoice) {
                if (! empty($filters['hidegraduated']) || ! empty($filters['hideinactive'])) {
                    $legacy = (array) $invoice;
                    $status = null;
                    if ($invoice->enrollment_id !== null) {
                        $enrollments ??= StudentEnrollment::with('events', 'stages')->whereIn('id',
                            (clone $query)->reorder()->select('i.enrollment_id')->whereNotNull('i.enrollment_id')->distinct()->pluck('i.enrollment_id'))->get()->keyBy('id');
                        $enrollment = $enrollments->get($invoice->enrollment_id);
                        if (! $enrollment) {
                            Enrollments::error("Invoice {$invoice->id} references a missing enrollment.");
                        }
                        if ($enrollment->mode === 'legacy') {
                            $legacy = $enrollment->legacy_context['student'] ?? null;
                            if ($legacy === null) {
                                Enrollments::error("Legacy enrollment {$enrollment->id} has no preserved accounting context.");
                            }
                        } else {
                            $status = EnrollmentAccounting::statusAt($enrollment, $asOf);
                        }
                    }
                    foreach (['hidegraduated' => ['graduated', 'actualdategrad'], 'hideinactive' => ['inactive', 'inactive_date']] as $filter => [$flag, $date]) {
                        $effective = $legacy[$date] ?? null;
                        if ($flag === 'graduated') {
                            $effective ??= $legacy['graddate'] ?? null;
                        } elseif ($effective === null) {
                            $effective = $invoice->enrollment_id !== null
                                ? $invoice->report_legacy_inactive_date : $invoice->report_inactive_date;
                        }
                        $hidden = $status !== null ? $status === $flag
                            : ($effective === null ? (bool) $invoice->$flag
                                : (bool) ($legacy[$flag] ?? false) && substr($effective, 0, 10) <= $asOf);
                        if (! empty($filters[$filter]) && $hidden) {
                            continue 2;
                        }
                    }
                }
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
                        ->select('id', 'student_id', 'transactiontype', 'processeddate', 'transactionamount')
                        ->orderBy('processeddate')->orderBy('id');
                    if (! empty($filters['invoicesentafterdate']) || ! empty($filters['invoicesentbeforedate'])) {
                        ReportTransactionDates::requireOurDate($payments);
                    }
                    if (! empty($filters['invoicesentafterdate'])) {
                        $payments->where('processeddate', '>=', $filters['invoicesentafterdate']);
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
                            'date' => ReportFormatting::date($payment->processeddate), 'amount' => $amount];
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
