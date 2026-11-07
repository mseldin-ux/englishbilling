@extends('layouts.app')
@section('content')
<h2>Invoices Report</h2>
@include('reports.invoices-form', ['filters' => $report['filters']])
<details><summary>Calculation rules and legacy exceptions</summary>
    <p>Sent-date bounds include midnight at both dates. Received rows match Payment/Credit substrings, regardless
        of invoice links. Their lower bound uses customer date and upper bound uses processed date.
        Invoiced/received totals sum absolute amounts; detail amounts keep their signs.
        Learner outstanding is clamped to zero and excess becomes prepayment. Overall outstanding is not clamped.
        Without Show Received, legacy learner outstanding stays zero while overall outstanding equals invoiced.</p>
</details>
<table id="report-totals"><thead><tr><th>Field</th><th>Total</th></tr></thead><tbody>
@foreach($report['totals'] as $field => $amount)<tr><td>{{ $field }}</td><td>{{ $amount }}</td></tr>@endforeach
</tbody></table>
<h3>Details — {{ count($report['groups']) }} learner group(s)</h3>
@forelse($report['groups'] as $group)
    <h3>@if($group['id'] !== null)<a href="{{ route('students.show', $group['id']) }}">Student {{ $group['id'] }}</a>@else UNKNOWN @endif</h3>
    <div class="table-scroll"><table class="record-table report-invoice-details">
        <thead><tr><th>Type</th><th>Name</th><th>ID</th><th>Date Sent</th><th>Date Received</th><th>Amount</th></tr></thead><tbody>
        @foreach($group['invoices'] as $invoice)
            <tr><td>Invoice</td><td>{{ $invoice['name'] }}</td><td>{{ $invoice['id'] }}</td><td>{{ $invoice['date'] }}</td><td></td><td>{{ $invoice['amount'] }}</td></tr>
        @endforeach
        @foreach($group['payments'] as $payment)
            <tr><td>{{ $payment['type'] }}</td><td></td><td>{{ $payment['id'] }}</td><td></td><td>{{ $payment['date'] }}</td><td>{{ $payment['amount'] }}</td></tr>
        @endforeach
        </tbody><tfoot>
        @foreach(['invoiced' => 'TOTAL INV', 'received' => 'TOTAL PAYMENTS', 'prepayment' => 'TOTAL PREPAYMENT', 'outstanding' => 'TOTAL OUTSTANDING'] as $field => $label)
            <tr><th colspan="5">{{ $label }}</th><td>{{ $group[$field] }}</td></tr>
        @endforeach
        </tfoot>
    </table></div>
@empty
    <p>No matching invoices.</p>
@endforelse
@endsection
