@extends('layouts.app')
@section('content')
<h2>Earned Income Report</h2>
@include('reports.earned-form', ['filters' => $report['filters']])
<p>AS OF: {{ $report['filters']['dateasof'] }}. {{ count($report['rows']) }} learner(s).</p>
<table id="report-totals"><thead><tr><th>Field</th><th>Total</th></tr></thead><tbody>
@foreach($report['totals'] as $field => $amount)<tr><td>{{ $field }}</td><td>{{ $amount }}</td></tr>@endforeach
</tbody></table>
<details><summary>Calculation rules and legacy exceptions</summary>
    <p>Includes enrollment on/after the first date and before the last enrollment date, with expected graduation
        on/after the first date or missing. Invoiced is the sum of absolute Fee and Declined Fee amounts across
        all transaction dates, including after the as-of date. Discounts, adjustments, payments, and writeoffs
        do not reduce it. Earned = invoiced × service ratio; unearned = invoiced − earned.</p>
    <p>Uses the same legacy program-service, lifecycle, and additive LOA rules as Attributable Income.
        Skip Zero excludes zero service progress, not zero balances or zero fees.
        Earned/unearned detail values show six decimals; totals are rounded once to cents.
        Missing sort values appear first, with learner ID breaking ties.</p>
</details>
<h3>Details</h3>
<div class="table-scroll"><table id="report-details" class="record-table">
    <thead><tr>@foreach(\App\Billing\EarnedIncome::FIELDS as $field)<th>{{ $field }}</th>@endforeach</tr></thead>
    <tbody>
    @forelse($report['rows'] as $row)
        <tr>@foreach($row as $field => $value)<td>@if($field === 'id')<a href="{{ route('students.show', $value) }}">{{ $value }}</a>@else{{ $value }}@endif</td>@endforeach</tr>
    @empty
        <tr><td colspan="{{ count(\App\Billing\EarnedIncome::FIELDS) }}">No matching learners.</td></tr>
    @endforelse
    </tbody>
</table></div>
@endsection
