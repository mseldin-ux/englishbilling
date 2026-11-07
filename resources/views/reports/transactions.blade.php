@extends('layouts.app')
@section('content')
<h2>Transactions Report</h2>
@include('reports.transactions-form', ['filters' => $report['filters']])
<p>Filters use processed dates (inclusive start, exclusive end); detail rows are ordered by customer transaction date.
    Blank dates or amounts leave that boundary unrestricted. Totals use signed amounts.
    Type selections are case-insensitive substring matches: “Payments only” matches “payment”, not Check or Pago.</p>
<h3>Totals</h3>
<table id="report-totals"><thead><tr><th>Type</th><th>Amount</th></tr></thead><tbody>
@foreach($report['totals'] as $type => $amount)<tr><td>{{ $type }}</td><td>{{ $amount }}</td></tr>@endforeach
</tbody></table>
<h3>Details — {{ count($report['rows']) }} transaction(s)</h3>
<div class="table-scroll">
<table id="report-details" class="record-table">
    <thead><tr>@foreach(\App\Billing\TransactionsReport::HEADERS as $header)<th>{{ $header }}</th>@endforeach</tr></thead>
    <tbody>
    @forelse($report['rows'] as $row)
        <tr><td>{{ $row['id'] }}</td>
            <td>@if($row['student_id'] !== null)<a href="{{ route('students.show', $row['student_id']) }}">{{ $row['student_id'] }}</a>@endif</td>
            <td>{{ $row['name'] }}</td><td>{{ $row['date'] }}</td><td>{{ $row['processed'] }}</td>
            <td>{{ $row['type'] }}</td><td>{{ $row['amount'] }}</td><td>{{ $row['notes'] }}</td></tr>
    @empty
        <tr><td colspan="8">No matching transactions.</td></tr>
    @endforelse
    </tbody>
</table>
</div>
@endsection
