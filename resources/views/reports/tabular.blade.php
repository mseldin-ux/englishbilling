@extends('layouts.app')
@section('content')
<h2>{{ $title }}</h2>
@include('reports.'.$kind.'-form', ['filters' => $report['filters']])
<details><summary>Calculation rules and legacy exceptions</summary><p>{{ $rules }}</p></details>
@if(isset($report['total']))
    <h3>TOTAL {{ $report['total'] }}</h3>
@else
    <table id="report-totals"><thead><tr><th>Field</th><th>Total</th></tr></thead><tbody>
    @foreach($report['totals'] as $field => $amount)<tr><td>{{ $field }}</td><td>{{ $amount }}</td></tr>@endforeach
    </tbody></table>
@endif
<h3>Details — {{ count($report['rows']) }} learner(s)</h3>
<div class="table-scroll"><table id="report-details" class="record-table">
    <thead><tr>@foreach($headers as $header)<th>{{ $header }}</th>@endforeach</tr></thead>
    <tbody>
    @forelse($report['rows'] as $row)
        <tr>@foreach($row as $field => $value)<td>@if($field === 'id' && $value !== null)<a href="{{ route('students.show', $value) }}">{{ $value }}</a>@elseif($field === 'name' && isset($report['displayNames'])){{ $report['displayNames'][$row['id']] }}@else{{ $value }}@endif</td>@endforeach</tr>
    @empty
        <tr><td colspan="{{ count($headers) }}">No matching learners.</td></tr>
    @endforelse
    </tbody>
</table></div>
@endsection
