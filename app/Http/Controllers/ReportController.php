<?php

namespace App\Http\Controllers;

use App\Billing\AttributableIncome;
use App\Billing\CollectionsReport;
use App\Billing\EarnedIncome;
use App\Billing\ExpectedIncome;
use App\Billing\InvoicesReport;
use App\Billing\PrepaysReport;
use App\Billing\ReceivablesReport;
use App\Billing\ReportCsv;
use App\Billing\TransactionsReport;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function index()
    {
        return view('reports.index', ['transactionTypes' => TransactionsReport::types()]);
    }

    public function expected(Request $request)
    {
        $filters = $request->validate($this->dateRules($request));
        $report = ExpectedIncome::run($filters);
        if (($filters['format'] ?? 'html') === 'csv') {
            return ReportCsv::download('cgms-plannedincome-report.csv', function (callable $write) use ($report) {
                $write(['CGMS Planned Income Report']);
                $write(['Parameters:']);
                $write($this->parameters($report['filters']));
                $write(['']);
                $write(['']);
                $write(['TOTAL', $report['total']]);
                foreach ($report['months'] as $month => $total) {
                    $write(['', $month, $total]);
                }
                $write(['']);
                $write(['DETAILS']);
                $write(ExpectedIncome::HEADERS);
                foreach ($report['rows'] as $row) {
                    $write([$row['date'], $row['student_id'], $row['first'], $row['last'], $row['amount'], $row['notes']]);
                }
            });
        }

        return response()->view('reports.expected', ['report' => $report])->header('Cache-Control', 'private, no-store');
    }

    public function earned(Request $request)
    {
        $filters = $request->validate([
            'startdate' => ['required', 'date_format:Y-m-d'],
            'lastpossiblestartdate' => ['required', 'date_format:Y-m-d', 'after:startdate'],
            'dateasof' => ['required', 'date_format:Y-m-d'],
            'sort' => ['nullable', 'in:ID,Last Name,Graduation Date'],
            'skipzero' => ['sometimes', 'in:yes'],
            'format' => ['sometimes', 'in:html,csv'],
        ]);
        $report = EarnedIncome::run($filters);
        if (($filters['format'] ?? 'html') === 'csv') {
            return ReportCsv::download('cgms-earned-income-report-'.$filters['dateasof'].'.csv', function (callable $write) use ($report) {
                $write(['CGMS Earned Income Report']);
                $write(['Parameters:']);
                $write(['', 'Enrolled by:', $report['filters']['startdate'], 'Last possible enrollment date:',
                    $report['filters']['lastpossiblestartdate'], 'Date As Of:', $report['filters']['dateasof']]);
                $write(['']);
                $write(['', 'TOTALS:']);
                foreach ($report['totals'] as $field => $amount) {
                    $write(['', '', $field, $amount]);
                }
                $write(['']);
                $write(EarnedIncome::FIELDS);
                foreach ($report['rows'] as $row) {
                    $write(array_values($row));
                }
            });
        }

        return response()->view('reports.earned', ['report' => $report])->header('Cache-Control', 'private, no-store');
    }

    public function transactions(Request $request)
    {
        $rules = $this->dateRules($request);
        $rules['transmin'] = ['nullable', 'numeric', 'regex:/^-?\d{1,13}(?:\.\d{1,2})?$/'];
        $rules['transmax'] = ['nullable', 'numeric', 'regex:/^-?\d{1,13}(?:\.\d{1,2})?$/'];
        if ($request->filled('transmin')) {
            $rules['transmax'][] = 'gte:transmin';
        }
        $rules['whichtransactions'] = ['nullable', 'string', 'max:255'];
        $filters = $request->validate($rules);
        $report = TransactionsReport::run($filters);
        if (($filters['format'] ?? 'html') === 'csv') {
            return ReportCsv::download('cgms-transactions-report.csv', function (callable $write) use ($report) {
                $write(['CGMS Transactions report']);
                $write(['Parameters:']);
                $write($this->parameters($report['filters'], [
                    'transmin' => 'minimum transaction amount', 'transmax' => 'maximum transaction amount',
                    'startdate' => 'start date', 'enddate' => 'end date', 'whichtransactions' => 'which transactions',
                ]));
                $write(['']);
                $write(['']);
                $write(['TOTALS']);
                foreach ($report['totals'] as $type => $amount) {
                    $write(['+', $type, $amount]);
                }
                $write(['']);
                $write(TransactionsReport::HEADERS);
                foreach ($report['rows'] as $row) {
                    $write(array_values($row));
                }
            });
        }

        return response()->view('reports.transactions', ['report' => $report, 'transactionTypes' => TransactionsReport::types()])
            ->header('Cache-Control', 'private, no-store');
    }

    public function collections(Request $request)
    {
        $rules = ['enrolldate' => ['nullable', 'date_format:Y-m-d'],
            'enrolledbeforedate' => ['nullable', 'date_format:Y-m-d'],
            'next_due_date' => ['nullable', 'date_format:Y-m-d'], 'hideinactive' => ['sometimes', 'boolean'],
            'format' => ['sometimes', 'in:html,csv']];
        if ($request->filled('enrolldate')) {
            $rules['enrolledbeforedate'][] = 'after:enrolldate';
        }
        foreach (['collectionsmin', 'collectionsmax'] as $field) {
            $rules[$field] = ['nullable', 'numeric', 'regex:/^-?\d{1,13}(?:\.\d{1,2})?$/'];
        }
        if ($request->filled('collectionsmin')) {
            $rules['collectionsmax'][] = 'gte:collectionsmin';
        }
        $filters = $request->validate($rules);
        $report = CollectionsReport::run($filters);
        if (($filters['format'] ?? 'html') === 'csv') {
            return ReportCsv::download('cgms-collections-report.csv', function (callable $write) use ($report) {
                $write(['CGMS Collections report']);
                $write(['Parameters:']);
                $write($this->parameters($report['filters'], ['enrolldate' => 'enrolled on or after',
                    'enrolledbeforedate' => 'enrolled before', 'next_due_date' => 'next payment due before',
                    'collectionsmin' => 'minimum due', 'collectionsmax' => 'maximum due', 'hideinactive' => 'exclude inactive']));
                $write(['']);
                $write(['TOTAL OWED', $report['total']]);
                $write(['']);
                $write(CollectionsReport::HEADERS);
                foreach ($report['rows'] as $row) {
                    $write(array_values($row));
                }
            });
        }

        return response()->view('reports.tabular', ['report' => $report, 'kind' => 'collections',
            'title' => 'Collections Report', 'headers' => CollectionsReport::HEADERS,
            'rules' => 'Graduated learners are excluded. Amount filters use the next payment amount, not the balance. Dates are inclusive enrollment start, exclusive enrollment end and due-before date. Hide inactive now works as selected. Total owed is the signed current balance sum.'])
            ->header('Cache-Control', 'private, no-store');
    }

    public function receivables(Request $request)
    {
        $filters = $request->validate(['startdate' => ['nullable', 'date_format:Y-m-d'],
            'enddate' => ['nullable', 'date_format:Y-m-d'], 'zeros' => ['sometimes', 'in:hidezero'],
            'format' => ['sometimes', 'in:html,csv']]);
        $report = ReceivablesReport::run($filters);
        if (($filters['format'] ?? 'html') === 'csv') {
            return ReportCsv::download('cgms-receivables-report-'.($filters['enddate'] ?? '').'.csv', function (callable $write) use ($report) {
                $write(['CGMS Receivables report']);
                $write(['Parameters:']);
                $write($this->parameters($report['filters'], ['startdate' => 'enrolled on or after', 'enddate' => 'as of (exclusive)', 'zeros' => 'row filter']));
                $write(['']);
                $write(['TOTAL', $report['total']]);
                $write(ReceivablesReport::HEADERS);
                foreach ($report['rows'] as $row) {
                    $write(array_values($row));
                }
            });
        }

        return response()->view('reports.tabular', ['report' => $report, 'kind' => 'receivables',
            'title' => 'Enrolled Students / Receivables Report', 'headers' => ReceivablesReport::HEADERS,
            'rules' => 'Signed transactions grouped by learner. Start date filters enrollment, not transaction dates. As-of excludes transactions whose customer OR processed date is on/after that date (or missing). Hide zero retains only positive rows, including exclusion of negative rows, but the overall total remains unfiltered as in legacy. Unassigned transactions form an UNKNOWN group unless the enrollment filter excludes them.'])
            ->header('Cache-Control', 'private, no-store');
    }

    public function invoices(Request $request)
    {
        $rules = ['studentenrolleddate' => ['nullable', 'date_format:Y-m-d'],
            'invoicesentafterdate' => ['nullable', 'date_format:Y-m-d'],
            'invoicesentbeforedate' => ['nullable', 'date_format:Y-m-d'],
            'hidegraduated' => ['sometimes', 'boolean'], 'hideinactive' => ['sometimes', 'boolean'],
            'showreceived' => ['sometimes', 'boolean'], 'format' => ['sometimes', 'in:html,csv']];
        if ($request->filled('invoicesentafterdate')) {
            $rules['invoicesentbeforedate'][] = 'after_or_equal:invoicesentafterdate';
        }
        $filters = $request->validate($rules);
        $report = InvoicesReport::run($filters);
        if (($filters['format'] ?? 'html') === 'csv') {
            return ReportCsv::download('cgms-invoices-report-'.($filters['invoicesentafterdate'] ?? '').'.csv', function (callable $write) use ($report) {
                $write(['CGMS Invoices report']);
                $write(['']);
                $write($this->parameters($report['filters'], ['studentenrolleddate' => 'enrolled on or after',
                    'invoicesentafterdate' => 'invoice sent on or after', 'invoicesentbeforedate' => 'invoice sent through midnight',
                    'hidegraduated' => 'exclude graduated', 'hideinactive' => 'exclude inactive', 'showreceived' => 'include received']));
                $write(['']);
                foreach ($report['totals'] as $field => $amount) {
                    $write([$field, $amount]);
                }
                $write(['']);
                $write(InvoicesReport::HEADERS);
                foreach ($report['groups'] as $group) {
                    $write(['------------------']);
                    foreach ($group['invoices'] as $invoice) {
                        $write([$group['id'], 'Invoice', $invoice['name'], $invoice['id'], $invoice['date'], '', '', $invoice['amount']]);
                    }
                    $write(['', '', '', '', '', '', 'TOTAL INV', '', $group['invoiced']]);
                    foreach ($group['payments'] as $payment) {
                        $write([$group['id'], $payment['type'], '', $payment['id'], '', $payment['date'], '', $payment['amount']]);
                    }
                    $write(['', '', '', '', '', '', 'TOTAL PAYMENTS', '', '', $group['received']]);
                    $write(['', '', '', '', '', '', 'TOTAL PREPAYMENT', '', '', '', $group['prepayment']]);
                    $write(['', '', '', '', '', '', 'TOTAL OUTSTANDING', '', '', '', '', $group['outstanding']]);
                }
            });
        }

        return response()->view('reports.invoices', ['report' => $report])->header('Cache-Control', 'private, no-store');
    }

    public function prepays(Request $request)
    {
        $filters = $request->validate([
            'startdate' => ['required', 'date_format:Y-m-d'],
            'lastpossiblestartdate' => ['required', 'date_format:Y-m-d', 'after:startdate'],
            'transstart' => ['required', 'date_format:Y-m-d'], 'dateasof' => ['required', 'date_format:Y-m-d', 'after:transstart'],
            'sort' => ['nullable', 'in:ID,Last Name,Graduation Date'], 'skipzero' => ['sometimes', 'in:yes'],
            'format' => ['sometimes', 'in:html,csv'],
        ]);
        $report = PrepaysReport::run($filters);
        if (($filters['format'] ?? 'html') === 'csv') {
            return ReportCsv::download('cgms-prepays-report-'.$filters['dateasof'].'.csv', function (callable $write) use ($report) {
                $write(['CGMS Prepays report']);
                $write([PrepaysReport::NOTICE]);
                $write(['Parameters:']);
                $write($this->parameters($report['filters'], ['startdate' => 'Enrolled by:', 'lastpossiblestartdate' => 'Last possible enrollment date:',
                    'transstart' => 'First Transaction Date:', 'dateasof' => 'Date As Of:']));
                $write(['']);
                $write(['', 'TOTALS:']);
                foreach ($report['totals'] as $field => $amount) {
                    $write(['', '', $field, $amount]);
                }
                $write(['']);
                $write(PrepaysReport::HEADERS);
                foreach ($report['rows'] as $row) {
                    $write(array_values($row));
                }
            });
        }

        return response()->view('reports.tabular', ['report' => $report, 'kind' => 'prepays',
            'title' => 'Prepays Report (Rebuilt)', 'headers' => PrepaysReport::HEADERS,
            'rules' => 'Uses expected graduation minus enrollment for service duration, not program service days. Adds all LOA intervals with every end clipped to as-of and missing starts inferred from enrollment. Transaction start is inclusive, as-of exclusive, using customer dates. Fee/discount/writeoff categories use absolute net signed sums. Payment amounts are normalized negative, except exact-spelling Refund is positive, then net sum is made absolute. Dated graduation/suspension/inactivity/actual graduation/withdrawal ends cash prepaid regardless of flags. Missing lifecycle dates stay missing. Accrual summary retains the legacy additional discount subtraction. Derived student amounts use six decimals; totals are rounded once to cents.'])
            ->header('Cache-Control', 'private, no-store');
    }

    private function dateRules(Request $request): array
    {
        $rules = ['startdate' => ['nullable', 'date_format:Y-m-d'],
            'enddate' => ['nullable', 'date_format:Y-m-d'], 'format' => ['sometimes', 'in:html,csv']];
        if ($request->filled('startdate')) {
            $rules['enddate'][] = 'after:startdate';
        }

        return $rules;
    }

    private function parameters(array $filters, array $labels = ['startdate' => 'start date', 'enddate' => 'end date']): array
    {
        $parameters = [''];
        foreach ($labels as $field => $label) {
            if (isset($filters[$field]) && $filters[$field] !== '' && ! ($field === 'whichtransactions' && $filters[$field] === 'all')) {
                array_push($parameters, $label, $filters[$field]);
            }
        }

        return $parameters;
    }

    public function attributable(Request $request)
    {
        $filters = $request->validate([
            'startdate' => ['required', 'date_format:Y-m-d'],
            'enddate' => ['required', 'date_format:Y-m-d', 'after:startdate'],
            'enrolldate' => ['nullable', 'date_format:Y-m-d'],
            'usesimplefields' => ['sometimes', 'boolean'],
            'format' => ['sometimes', 'in:html,csv'],
        ]);
        $report = AttributableIncome::run($filters);
        if (($filters['format'] ?? 'html') === 'csv') {
            return ReportCsv::download('cgms-attributableincome-report.csv', function (callable $write) use ($report) {
                $write([$report['title']]);
                $write(['Parameters:']);
                $write(['', 'start date', $report['filters']['startdate'], 'end date', $report['filters']['enddate'],
                    'enroll on or after', $report['filters']['enrolldate'] ?? '']);
                $write(['']);
                $write(['']);
                $write(['TOTALS FOR ALL TRANSACTIONS BY TYPE']);
                $write(['Totals are literally the sum of the absolute value of each type of transaction in the period']);
                foreach ($report['types'] as $type => $amount) {
                    $write(['', $type, $amount]);
                }
                $write(['TOTALS FOR CALCULATED FIELDS']);
                foreach (self::explanations() as $explanation) {
                    $write([$explanation]);
                }
                foreach (AttributableIncome::SUMMARY_FIELDS as $field) {
                    $write(['', $field, $report['calculated'][$field]]);
                }
                $write(['']);
                $write(['DETAILS BY AL']);
                $write(['', ...$report['csvFields']]);
                foreach ($report['rows'] as $row) {
                    $values = [];
                    foreach ($report['csvFields'] as $field) {
                        $value = $row[$field] ?? '0.00';
                        if ($field !== 'earnedincome' && ! empty($report['filters']['usesimplefields']) && is_numeric($value)) {
                            $value = (string) BigDecimal::of($value)->toScale(2, RoundingMode::Down);
                        }
                        $values[] = $value;
                    }
                    $write(['', ...$values]);
                }
            });
        }

        return response()->view('reports.attributable', ['report' => $report, 'explanations' => self::explanations()])
            ->header('Cache-Control', 'private, no-store');
    }

    private static function explanations(): array
    {
        return [
            'adjustedowed = invoiced - discounts - adjustments.',
            'adjustedpaid = paid + credits - abs(payment adjustments).',
            'ar (overall) = abs(adjustedowed) - abs(adjustedpaid) - abs(writeoffs). Learner rows use signed adjustedowed.',
            'pct = service time / program service days, capped at 100%, with legacy lifecycle and LOA rules.',
            'totalearned = sum of adjustedowed * pct, rounded once after aggregation.',
        ];
    }
}
