@extends('layouts.app')
@section('content')
@if(!auth()->user()->canAccessBilling())
<h2>Student Records</h2>
<p><a href="{{ route('workspace.index') }}">Open student records table</a></p>
@else
<h2>Billing foundation</h2>
<p><a href="{{ route('students.index', ['reset'=>1]) }}">Browse all ALs</a> |
    <a href="{{ route('students.create') }}">Create an AL</a>
    @if(auth()->user()->canManagePaymentPlans())
        | <a href="{{ route('plans.index') }}">Payment Plans</a>
    @endif
</p>
<p>Browse/search, learner details, ledger entries, invoice records, LOAs, and schedules are available.
    Invoice/statement HTML previews and PDF downloads are available.
    @if(auth()->user()->canViewReports())
        <a href="{{ route('reports.index') }}">All eight reports</a> are available.
        Rebuilt Prepays still requires accounting acceptance.
    @endif
</p>
<table>
    <thead><tr><th>Billing table</th><th>Records</th></tr></thead>
    <tbody>
    @foreach($counts as $table => $count)
        <tr><td>{{ $table }}</td><td>{{ number_format($count) }}</td></tr>
    @endforeach
    </tbody>
</table>
@endif
@endsection
