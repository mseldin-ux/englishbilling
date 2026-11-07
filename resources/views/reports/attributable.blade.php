@extends('layouts.app')
@section('content')
<h2>Attributable Income Report</h2>
@include('reports.attributable-form', ['filters' => $report['filters']])
<p>{{ $report['filters']['startdate'] }} through before {{ $report['filters']['enddate'] }}.
    @if(!empty($report['filters']['enrolldate'])) Enrolled on or after {{ $report['filters']['enrolldate'] }}.@endif
    {{ count($report['rows']) }} learner(s).</p>
<div class="field-grid">
    <div><h3>Totals</h3><p>Sum of absolute transaction amounts by type.</p>
        <table id="report-types"><thead><tr><th>Type</th><th>Total</th></tr></thead><tbody>
        @foreach($report['types'] as $type => $amount)<tr><td>{{ $type }}</td><td>{{ $amount }}</td></tr>@endforeach
        </tbody></table>
    </div>
    <div><h3>Calculated</h3>
        <table id="report-calculated"><thead><tr><th>Field</th><th>Total</th></tr></thead><tbody>
        @foreach(\App\Billing\AttributableIncome::SUMMARY_FIELDS as $field)
            <tr><td>{{ $field }}</td><td>{{ $report['calculated'][$field] }}</td></tr>
        @endforeach
        </tbody></table>
    </div>
</div>
<details><summary>Calculation rules and legacy exceptions</summary>
    <ul>@foreach($explanations as $explanation)<li>{{ $explanation }}</li>@endforeach</ul>
    <p>Refunds/reversals appear in type totals but are not deducted from the legacy paid calculation.
        “Discounts” (plural) is not a recognized discount category in this report.
        Archived learners enrolled before 2022 and certain completed lifecycle states are forced to 100%.
        LOAs are added individually using the legacy last-row clipping rule, not merged.
        Suspension sets the diagnostic last date but does not cap service time; PIF alone does not force 100%.
        Learner earned income shows six decimal places for reconciliation; the aggregate is rounded once to cents.</p>
</details>
<h3>Details</h3>
<div class="table-scroll">
<table id="report-details" class="record-table">
    <thead><tr>@foreach($report['fields'] as $field)<th>{{ $field }}</th>@endforeach</tr></thead>
    <tbody>
    @forelse($report['rows'] as $row)
        <tr>@foreach($report['fields'] as $field)
            <td>@if($field === 'id')<a href="{{ route('students.show', $row['id']) }}">{{ $row['id'] }}</a>@else{{ $row[$field] ?? '0.00' }}@endif</td>
        @endforeach</tr>
    @empty
        <tr><td colspan="{{ count($report['fields']) }}">No matching transactions.</td></tr>
    @endforelse
    </tbody>
</table>
</div>
@endsection
