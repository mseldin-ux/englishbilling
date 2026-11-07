@extends('layouts.app')
@section('content')
<h2>Expected Income Report</h2>
@include('reports.expected-form', ['filters' => $report['filters']])
<p>Scheduled payment due dates, inclusive start and exclusive end. Blank dates leave that boundary unrestricted.
    Includes all scheduled payments regardless of student status or invoice/payment activity.</p>
<h3>TOTAL {{ $report['total'] }}</h3>
<h3>Subtotals by year / month</h3>
<table id="report-months"><thead><tr><th>Year / Month</th><th>Amount</th></tr></thead><tbody>
@foreach($report['months'] as $month => $amount)<tr><td>{{ $month }}</td><td>{{ $amount }}</td></tr>@endforeach
</tbody></table>
<h3>Details — {{ count($report['rows']) }} scheduled payment(s)</h3>
<div class="table-scroll">
<table id="report-details" class="record-table">
    <thead><tr><th>SID</th><th>Name</th><th>Date</th><th>Amount</th><th>Notes</th></tr></thead>
    <tbody>
    @forelse($report['rows'] as $row)
        <tr><td>@if($row['student_id'] !== null)<a href="{{ route('students.show', $row['student_id']) }}">{{ $row['student_id'] }}</a>@else UNKNOWN @endif</td>
            <td>{{ $row['name'] }}</td><td>{{ substr($row['date'], 0, 10) }}</td><td>{{ $row['amount'] }}</td><td>{{ $row['notes'] }}</td></tr>
    @empty
        <tr><td colspan="5">No matching scheduled payments.</td></tr>
    @endforelse
    </tbody>
</table>
</div>
@endsection
