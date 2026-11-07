@extends('layouts.app')
@section('content')
<h2>Reports</h2>
<h2>Attributable Income Report</h2>
<p>Income recognized from fees in the selected period, less fee adjustments and discounts, with program-service calculations.</p>
@include('reports.attributable-form', ['filters' => old()])
<p class="hint">Start date is inclusive; end date is exclusive (midnight at the start of that date). Program progress is measured as of the end date.</p>
<h2>Expected Income Report</h2>
<p>Planned payments by due date, with monthly subtotals. Dates are optional.</p>
@include('reports.expected-form', ['filters' => old()])
<h2>Transactions Report</h2>
<p>Transactions filtered by processed date, signed amount, and transaction-type substring. Filters are optional.</p>
@include('reports.transactions-form', ['filters' => old()])
<h2>Earned Income Report</h2>
<p>All-date fees for students in an enrollment window, prorated by service progress as of a date, with earned/unearned totals.</p>
@include('reports.earned-form', ['filters' => old()])
<h2>Collections Report</h2>
<p>Next-payment filters and current balances for non-graduated learners. Filters are optional.</p>
@include('reports.collections-form', ['filters' => old()])
<h2>Invoices Report</h2>
<p>Invoice metadata and optional received payments/credits, grouped by learner. Filters are optional.</p>
@include('reports.invoices-form', ['filters' => old()])
<h2>Enrolled Students / Receivables Report</h2>
<p>Signed transaction totals by learner, with optional enrollment start and transaction as-of cutoff.</p>
@include('reports.receivables-form', ['filters' => old()])
<h2>Prepays Report (Rebuilt)</h2>
@include('reports.prepays-form', ['filters' => old()])
@endsection
